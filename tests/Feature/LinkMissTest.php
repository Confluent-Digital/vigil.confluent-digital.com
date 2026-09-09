<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Core\LinkMiss;
use App\Tests\DatabaseTestCase;

/**
 * Un 404 sur `/c/` n'est pas une page manquante : c'est un lien EN CIRCULATION
 * qui envoie du trafic dans le vide, silencieusement. Ces tests verrouillent
 * les deux proprietes qui rendent le journal utilisable : le diagnostic
 * distingue les cinq causes, et le comptage est agrege.
 */
final class LinkMissTest extends DatabaseTestCase
{
    private function diagnostiquer(string $token): array
    {
        return LinkMiss::diagnose($this->pdo, $token);
    }

    public function testJetonInconnu(): void
    {
        self::assertSame('token_inconnu', $this->diagnostiquer('nexistepas42')['raison']);
    }

    public function testAccesSuspendu(): void
    {
        $ctx = $this->makeCampaign();
        $this->pdo->prepare("UPDATE t_campaign_publisher SET cp_status='paused' WHERE cp_token=:t")
            ->execute(['t' => $ctx['token']]);

        $d = $this->diagnostiquer($ctx['token']);

        self::assertSame('acces_suspendu', $d['raison']);
        self::assertSame($ctx['campaign_id'], $d['campagne'], 'la campagne doit etre identifiee : c\'est ce qui rend le probleme reparable');
        self::assertSame($ctx['publisher_id'], $d['publisher']);
    }

    public function testCampagneSuspendue(): void
    {
        $ctx = $this->makeCampaign(['campaign_status' => 'paused']);

        self::assertSame('campagne_suspendue', $this->diagnostiquer($ctx['token'])['raison']);
    }

    public function testPublisherInactif(): void
    {
        $ctx = $this->makeCampaign();
        $this->pdo->prepare('UPDATE t_publisher SET publisher_status=:s WHERE publisher_id=:i')
            ->execute(['s' => 'archived', 'i' => $ctx['publisher_id']]);

        self::assertSame('publisher_inactif', $this->diagnostiquer($ctx['token'])['raison']);
    }

    public function testCampagneExpiree(): void
    {
        $ctx = $this->makeCampaign();
        $this->pdo->prepare('UPDATE t_campaign SET campaign_date_stop=:d WHERE campaign_id=:i')
            ->execute(['d' => gmdate('Y-m-d', strtotime('-1 day')), 'i' => $ctx['campaign_id']]);

        self::assertSame('campagne_expiree', $this->diagnostiquer($ctx['token'])['raison']);
    }

    /**
     * L'ordre compte : le libelle doit designer ce qu'il faut corriger, pas
     * une consequence. Un acces suspendu sur une campagne elle-meme en pause
     * reste d'abord un probleme d'acces.
     */
    public function testLeDiagnosticDesigneLaCauseLaPlusSpecifique(): void
    {
        $ctx = $this->makeCampaign(['campaign_status' => 'paused']);
        $this->pdo->prepare("UPDATE t_campaign_publisher SET cp_status='paused' WHERE cp_token=:t")
            ->execute(['t' => $ctx['token']]);

        self::assertSame('acces_suspendu', $this->diagnostiquer($ctx['token'])['raison']);
    }

    /**
     * Sans agregation, un lien mort tres visite — ou un scanner — ferait de
     * cette table un levier d'amplification : une ecriture par requete, sur le
     * chemin chaud, sans borne.
     */
    public function testLeComptageEstAgregeParJetonEtParJour(): void
    {
        $diag = ['raison' => 'token_inconnu', 'campagne' => null, 'publisher' => null];

        for ($i = 0; $i < 50; $i++) {
            LinkMiss::record($this->pdo, 'jetonmort', $diag, '203.0.113.7', 'https://exemple.test/');
        }

        self::assertSame(1, $this->countRows('t_link_miss', 'miss_token = :t', ['t' => 'jetonmort']));

        $stmt = $this->pdo->prepare('SELECT miss_count FROM t_link_miss WHERE miss_token = :t');
        $stmt->execute(['t' => 'jetonmort']);
        self::assertSame(50, (int) $stmt->fetchColumn());
    }

    public function testLesJetonsMalformesTiennentSousUneLigne(): void
    {
        $diag = ['raison' => 'token_malforme', 'campagne' => null, 'publisher' => null];

        for ($i = 0; $i < 30; $i++) {
            LinkMiss::record($this->pdo, LinkMiss::TOKEN_MALFORME, $diag, null, null);
        }

        self::assertSame(
            1,
            $this->countRows('t_link_miss', 'miss_reason = :r', ['r' => 'token_malforme']),
            'toutes les saisies aberrantes doivent tenir sous une seule ligne par jour'
        );
    }

    public function testLaReparationEstIdentifiable(): void
    {
        $ctx = $this->makeCampaign(['campaign_status' => 'paused']);
        $d   = $this->diagnostiquer($ctx['token']);

        LinkMiss::record($this->pdo, $ctx['token'], $d, '203.0.113.7', null);

        $stmt = $this->pdo->prepare(
            'SELECT miss_id_campaign, miss_reason FROM t_link_miss WHERE miss_token = :t'
        );
        $stmt->execute(['t' => $ctx['token']]);
        $ligne = $stmt->fetch();

        self::assertSame($ctx['campaign_id'], (int) $ligne['miss_id_campaign']);
        self::assertSame('campagne_suspendue', $ligne['miss_reason']);
    }

    /** La page publique ne doit jamais renseigner sur l'etat des operations. */
    public function testLaPagePubliqueNeDivulguePasLaCause(): void
    {
        $page = LinkMiss::page();

        foreach (['pause', 'campagne', 'publisher', 'suspendu', 'archiv', 'jeton', 'token'] as $mot) {
            self::assertStringNotContainsString(
                $mot,
                mb_strtolower($page),
                "« $mot » renseignerait un concurrent sur l'etat de vos operations"
            );
        }
    }

    public function testFormatDeJeton(): void
    {
        self::assertTrue(LinkMiss::isWellFormed('sbxAbCd12345'));
        self::assertFalse(LinkMiss::isWellFormed(''));
        self::assertFalse(LinkMiss::isWellFormed('abc'));
        self::assertFalse(LinkMiss::isWellFormed('avec-tiret-x'));
        self::assertFalse(LinkMiss::isWellFormed(str_repeat('a', 30)));
    }
}
