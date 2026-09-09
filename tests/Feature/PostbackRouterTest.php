<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Core\PostbackRouter;
use App\Tests\DatabaseTestCase;

final class PostbackRouterTest extends DatabaseTestCase
{
    /** @return int identifiant de la destination creee */
    private function makeDestination(?int $campaignId, array $overrides = []): int
    {
        $this->pdo->prepare(
            'INSERT INTO t_campaign_postback
                 (cpb_id_campaign, cpb_id_publisher, cpb_name, cpb_url, cpb_on_status, cpb_status)
             VALUES (:c, :p, :n, :u, :s, :st)'
        )->execute([
            'c'  => $campaignId,
            'p'  => $overrides['publisher'] ?? null,
            'n'  => $overrides['name'] ?? 'Plateforme',
            'u'  => $overrides['url'] ?? 'https://ext.test/pb?cid={external_clickid}&sum={payout}',
            's'  => $overrides['on_status'] ?? 'approved',
            'st' => $overrides['status'] ?? 'active',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function makeConversion(array $ctx, string $ulid): int
    {
        $this->pdo->prepare(
            'INSERT INTO t_conversion
                 (conversion_id_click, conversion_click_date, conversion_id_campaign,
                  conversion_id_publisher, conversion_external_txid, conversion_status)
             VALUES (:i, UTC_TIMESTAMP(3), :c, :p, :t, \'approved\')'
        )->execute([
            'i' => \App\Core\Ulid::toBinary($ulid),
            'c' => $ctx['campaign_id'],
            'p' => $ctx['publisher_id'],
            't' => 'TX-' . substr($ulid, -6),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function testLesMacrosSontResoluesALEmpilement(): void
    {
        $ctx = $this->makeCampaign();
        $this->makeDestination($ctx['campaign_id']);
        $ulid = $this->makeClick($ctx);
        $id   = $this->makeConversion($ctx, $ulid);

        PostbackRouter::enqueue($this->pdo, $id, $ctx['campaign_id'], $ctx['publisher_id'], 'approved', [
            'external_clickid' => 'EXT-99',
            'payout'           => '12.5000',
        ]);

        $url = $this->pdo->query('SELECT pq_url FROM t_postback_queue ORDER BY pq_id DESC LIMIT 1')->fetchColumn();

        self::assertSame('https://ext.test/pb?cid=EXT-99&sum=12.5000', $url);
    }

    /**
     * Le garde-fou contre la course : deux postbacks simultanes peuvent tous
     * deux conclure « conversion nouvelle » avant que l'un ait ecrit.
     */
    public function testUnRelaiIdentiqueDejaEnAttenteNEstPasDouble(): void
    {
        $ctx = $this->makeCampaign();
        $this->makeDestination($ctx['campaign_id']);
        $ulid = $this->makeClick($ctx);
        $id   = $this->makeConversion($ctx, $ulid);

        $contexte = ['external_clickid' => 'EXT-1', 'payout' => '10.0000'];

        self::assertSame(1, PostbackRouter::enqueue($this->pdo, $id, $ctx['campaign_id'], $ctx['publisher_id'], 'approved', $contexte));
        self::assertSame(0, PostbackRouter::enqueue($this->pdo, $id, $ctx['campaign_id'], $ctx['publisher_id'], 'approved', $contexte));
        self::assertSame(1, $this->countRows('t_postback_queue', 'pq_id_conversion = :i', ['i' => $id]));
    }

    /**
     * Une annulation ne repart que si l'URL de relai porte `{status}` : sans
     * cette macro, le second appel serait rigoureusement identique au premier,
     * la plateforme ne pourrait pas les distinguer, et en recevoir deux lui
     * ferait compter deux ventes.
     */
    public function testUnChargebackRepartSiLUrlPorteLeStatut(): void
    {
        $ctx = $this->makeCampaign();
        $this->makeDestination($ctx['campaign_id'], [
            'on_status' => 'approved,chargeback',
            'url'       => 'https://ext.test/pb?cid={external_clickid}&st={status}',
        ]);
        $ulid = $this->makeClick($ctx);
        $id   = $this->makeConversion($ctx, $ulid);

        PostbackRouter::enqueue($this->pdo, $id, $ctx['campaign_id'], $ctx['publisher_id'], 'approved',
            ['external_clickid' => 'E', 'status' => 'approved']);
        PostbackRouter::enqueue($this->pdo, $id, $ctx['campaign_id'], $ctx['publisher_id'], 'chargeback',
            ['external_clickid' => 'E', 'status' => 'chargeback']);

        self::assertSame(2, $this->countRows('t_postback_queue', 'pq_id_conversion = :i', ['i' => $id]));
    }

    /**
     * Le revers, fige ici pour qu'il reste un choix conscient : une URL sans
     * `{status}` ne peut PAS repercuter une annulation — le second relai est
     * supprime comme doublon. Le back-office doit le signaler a la saisie,
     * sinon la campagne perd ses chargebacks en silence.
     */
    public function testSansMacroStatutLAnnulationEstSupprimeeCommeDoublon(): void
    {
        $ctx = $this->makeCampaign();
        $this->makeDestination($ctx['campaign_id'], [
            'on_status' => 'approved,chargeback',
            'url'       => 'https://ext.test/pb?cid={external_clickid}',
        ]);
        $ulid = $this->makeClick($ctx);
        $id   = $this->makeConversion($ctx, $ulid);

        PostbackRouter::enqueue($this->pdo, $id, $ctx['campaign_id'], $ctx['publisher_id'], 'approved',
            ['external_clickid' => 'E', 'status' => 'approved']);
        PostbackRouter::enqueue($this->pdo, $id, $ctx['campaign_id'], $ctx['publisher_id'], 'chargeback',
            ['external_clickid' => 'E', 'status' => 'chargeback']);

        self::assertSame(1, $this->countRows('t_postback_queue', 'pq_id_conversion = :i', ['i' => $id]));
    }

    public function testDestinationEnPauseIgnoree(): void
    {
        $ctx = $this->makeCampaign();
        $this->makeDestination($ctx['campaign_id'], ['status' => 'paused']);
        $ulid = $this->makeClick($ctx);
        $id   = $this->makeConversion($ctx, $ulid);

        self::assertSame(0, PostbackRouter::enqueue($this->pdo, $id, $ctx['campaign_id'], $ctx['publisher_id'], 'approved', []));
    }

    public function testDestinationCibleeSurUnAutrePublisherIgnoree(): void
    {
        $ctx = $this->makeCampaign();
        $this->makeDestination($ctx['campaign_id'], ['publisher' => $ctx['publisher_id'] + 999]);
        $ulid = $this->makeClick($ctx);
        $id   = $this->makeConversion($ctx, $ulid);

        self::assertSame(0, PostbackRouter::enqueue($this->pdo, $id, $ctx['campaign_id'], $ctx['publisher_id'], 'approved', []));
    }

    public function testStatutNonDeclencheurIgnore(): void
    {
        $ctx = $this->makeCampaign();
        $this->makeDestination($ctx['campaign_id'], ['on_status' => 'approved']);
        $ulid = $this->makeClick($ctx);
        $id   = $this->makeConversion($ctx, $ulid);

        self::assertSame(0, PostbackRouter::enqueue($this->pdo, $id, $ctx['campaign_id'], $ctx['publisher_id'], 'rejected', []));
    }

    // ── Les trois portees ───────────────────────────────────────────────────

    /**
     * Le cas d'usage qui a motive la portee publisher : son pixel de conversion
     * se declare UNE fois et se declenche sur toutes ses conversions, quelle que
     * soit la campagne. Sans cela il fallait recopier la meme ligne sur chaque
     * campagne — et un oubli faisait disparaitre ses conversions en silence.
     */
    public function testLePixelDUnPublisherSeDeclencheSurToutesSesCampagnes(): void
    {
        $a = $this->makeCampaign();
        $b = $this->makeCampaign();

        // Une seule destination, attachee au publisher de la campagne A.
        $this->makeDestination(null, [
            'publisher' => $a['publisher_id'],
            'name'      => 'Pixel du publisher',
            'url'       => 'https://pub.test/px?cid={external_clickid}',
        ]);

        $conv = $this->makeConversion($a, $this->makeClick($a));
        self::assertSame(
            1,
            PostbackRouter::enqueue($this->pdo, $conv, $a['campaign_id'], $a['publisher_id'], 'approved', []),
            'le pixel se declenche sur la campagne A'
        );

        // Le meme publisher, une autre campagne : le pixel doit suivre.
        $conv2 = $this->makeConversion($b, $this->makeClick($b));
        self::assertSame(
            1,
            PostbackRouter::enqueue($this->pdo, $conv2, $b['campaign_id'], $a['publisher_id'], 'approved', []),
            'le meme pixel suit le publisher sur la campagne B, sans ligne supplementaire'
        );
    }

    public function testLePixelDUnPublisherNeTouchePasLesAutresPublishers(): void
    {
        $a     = $this->makeCampaign();
        $autre = $this->makeCampaign();

        $this->makeDestination(null, ['publisher' => $a['publisher_id']]);

        $conv = $this->makeConversion($a, $this->makeClick($a));

        self::assertSame(
            0,
            PostbackRouter::enqueue($this->pdo, $conv, $a['campaign_id'], $autre['publisher_id'], 'approved', []),
            'la conversion d\'un autre publisher ne declenche pas ce pixel'
        );
    }

    public function testLesTroisPorteesSeCumulent(): void
    {
        $ctx = $this->makeCampaign();

        // 1. le pixel du publisher
        $this->makeDestination(null, ['publisher' => $ctx['publisher_id'], 'url' => 'https://pub.test/px']);
        // 2. la plateforme externe de la campagne
        $this->makeDestination($ctx['campaign_id'], ['url' => 'https://ext.test/pb']);
        // 3. l'exception negociee sur ce couple precis
        $this->makeDestination($ctx['campaign_id'], [
            'publisher' => $ctx['publisher_id'], 'url' => 'https://exception.test/pb',
        ]);

        $conv = $this->makeConversion($ctx, $this->makeClick($ctx));

        self::assertSame(
            3,
            PostbackRouter::enqueue($this->pdo, $conv, $ctx['campaign_id'], $ctx['publisher_id'], 'approved', []),
            'les trois portees se declenchent ensemble'
        );
    }

    /**
     * Une destination sans campagne NI publisher se declencherait sur toutes
     * les conversions de tout le monde. La base l'interdit par une contrainte
     * CHECK ; le routeur l'exclut aussi, pour ne pas dependre d'une contrainte
     * qu'une migration future pourrait retirer.
     */
    public function testLaBaseRefuseUneDestinationSansPortee(): void
    {
        $this->expectException(\PDOException::class);

        $this->makeDestination(null, ['publisher' => null]);
    }

    public function testAnalyseDesStatutsDeclencheurs(): void
    {
        self::assertTrue(PostbackRouter::triggersOn('approved', 'approved'));
        self::assertTrue(PostbackRouter::triggersOn('approved, chargeback', 'CHARGEBACK'));
        self::assertFalse(PostbackRouter::triggersOn('approved', 'rejected'));
        self::assertTrue(PostbackRouter::triggersOn('', 'approved'), 'vide = approved par defaut');
        self::assertFalse(PostbackRouter::triggersOn('', 'pending'));
    }
}
