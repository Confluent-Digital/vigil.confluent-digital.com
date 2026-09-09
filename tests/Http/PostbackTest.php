<?php

declare(strict_types=1);

namespace App\Tests\Http;

final class PostbackTest extends HttpTestCase
{
    /** Fait un clic reel et rend son clickid. */
    private function clic(array $ctx, string $query = ''): string
    {
        $r = $this->get('/c/' . $ctx['token'] . ($query !== '' ? '?' . $query : ''));
        parse_str((string) parse_url($r['headers']['location'], PHP_URL_QUERY), $p);
        usleep(300000);

        return (string) $p['c'];
    }

    private function compteConversions(int $campaignId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM t_conversion WHERE conversion_id_campaign = :c'
        );
        $stmt->execute(['c' => $campaignId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * LE test du systeme. Un money site retente son postback — delai depasse,
     * tache de rattrapage, double clic d'un operateur. Sans la contrainte
     * UNIQUE, chaque tentative creerait une conversion, relayee chez le
     * partenaire. Ces doublons-la ne se rattrapent pas.
     */
    public function testCinqPostbacksIdentiquesNeFontQuUneConversion(): void
    {
        $ctx  = $this->makeCampaign('https://money.test/?c={clickid}');
        $clic = $this->clic($ctx);

        for ($i = 0; $i < 5; $i++) {
            $r = $this->get(sprintf(
                '/pb?clickid=%s&txid=TX-1&payout=12.5&s=%s', $clic, $ctx['secret']
            ));
            self::assertSame(200, $r['status'], 'le money site recoit toujours 200');
        }

        self::assertSame(1, $this->compteConversions($ctx['campaign_id']));
    }

    public function testUnTxidDifferentCreeUneSecondeConversion(): void
    {
        $ctx  = $this->makeCampaign('https://money.test/?c={clickid}');
        $clic = $this->clic($ctx);

        $this->get("/pb?clickid=$clic&txid=TX-1&payout=10&s={$ctx['secret']}");
        $this->get("/pb?clickid=$clic&txid=TX-2&payout=30&s={$ctx['secret']}");

        self::assertSame(2, $this->compteConversions($ctx['campaign_id']), 'deux ventes sur un meme clic');
    }

    public function testUnChangementDeStatutMetAJourSansDupliquer(): void
    {
        $ctx  = $this->makeCampaign('https://money.test/?c={clickid}');
        $clic = $this->clic($ctx);

        $this->get("/pb?clickid=$clic&txid=TX-1&payout=10&s={$ctx['secret']}");
        $this->get("/pb?clickid=$clic&txid=TX-1&status=chargeback&s={$ctx['secret']}");

        $stmt = $this->pdo->prepare(
            'SELECT conversion_status, conversion_date_update IS NOT NULL AS maj
               FROM t_conversion WHERE conversion_id_campaign = :c'
        );
        $stmt->execute(['c' => $ctx['campaign_id']]);
        $lignes = $stmt->fetchAll();

        self::assertCount(1, $lignes);
        self::assertSame('chargeback', $lignes[0]['conversion_status']);
        self::assertSame(1, (int) $lignes[0]['maj']);
    }

    /**
     * Le refus est silencieux cote money site : lui dire « secret invalide »
     * l'aiderait a le deviner. Mais aucune conversion ne doit etre creee.
     */
    public function testMauvaisSecretRefuseMaisRepond200(): void
    {
        $ctx  = $this->makeCampaign('https://money.test/?c={clickid}');
        $clic = $this->clic($ctx);

        $r = $this->get("/pb?clickid=$clic&txid=TX-1&payout=10&s=mauvais");

        self::assertSame(200, $r['status']);
        self::assertSame(0, $this->compteConversions($ctx['campaign_id']));
    }

    public function testSecretAbsentRefuse(): void
    {
        $ctx  = $this->makeCampaign('https://money.test/?c={clickid}');
        $clic = $this->clic($ctx);

        $this->get("/pb?clickid=$clic&txid=TX-1&payout=10");

        self::assertSame(0, $this->compteConversions($ctx['campaign_id']));
    }

    /**
     * Un clickid inconnu rend tout de meme 200 : un 404 declencherait chez le
     * money site des files de retry qui polluent ses logs pour une conversion
     * qui, de toute facon, ne sera jamais rattachable.
     */
    public function testClickidInconnuRepond200SansRienCreer(): void
    {
        $inconnu = \App\Core\Ulid::generate();

        self::assertSame(200, $this->get("/pb?clickid=$inconnu&txid=TX-1")['status']);
        self::assertSame(200, $this->get('/pb?clickid=pas-un-ulid&txid=TX-1')['status']);
        self::assertSame(200, $this->get('/pb')['status']);
    }

    public function testLePayoutDeLaCampagneSAppliqueParDefaut(): void
    {
        $ctx  = $this->makeCampaign('https://money.test/?c={clickid}');
        $clic = $this->clic($ctx);

        $this->get("/pb?clickid=$clic&txid=TX-1&s={$ctx['secret']}");

        $stmt = $this->pdo->prepare(
            'SELECT conversion_payout FROM t_conversion WHERE conversion_id_campaign = :c'
        );
        $stmt->execute(['c' => $ctx['campaign_id']]);

        self::assertSame('10.0000', $stmt->fetchColumn());
    }

    /**
     * Le money site branche sur la plateforme externe nomme le montant
     * `amount`. On ne peut pas lui imposer `payout` : le postback se configure
     * chez lui, souvent dans une interface sans champ libre. Non reconnu, le
     * montant serait silencieusement remplace par celui de la campagne.
     */
    public function testLeMontantEstAccepteSousLeNomAmount(): void
    {
        $ctx  = $this->makeCampaign('https://money.test/?c={clickid}');
        $clic = $this->clic($ctx);

        $this->get("/pb?clickid=$clic&txid=TX-AMOUNT&amount=42.5&s={$ctx['secret']}");

        $stmt = $this->pdo->prepare(
            "SELECT conversion_payout FROM t_conversion
              WHERE conversion_id_campaign = :c AND conversion_external_txid = 'TX-AMOUNT'"
        );
        $stmt->execute(['c' => $ctx['campaign_id']]);

        self::assertSame('42.5000', $stmt->fetchColumn());
    }

    /**
     * Le piege : `??` ne bascule que sur `null`, pas sur la chaine vide. Un
     * money site qui emet TOUJOURS `payout=`, parfois vide, voyait donc son
     * `amount` ignore — et le montant remplace en silence par celui de la
     * campagne. C'est le cas le plus courant d'un postback configure dans une
     * interface a gabarit fixe.
     */
    public function testUnPayoutVideLaisseAmountSAppliquer(): void
    {
        $ctx  = $this->makeCampaign('https://money.test/?c={clickid}');
        $clic = $this->clic($ctx);

        $this->get("/pb?clickid=$clic&txid=TX-VIDE&payout=&amount=33&s={$ctx['secret']}");

        $stmt = $this->pdo->prepare(
            "SELECT conversion_payout FROM t_conversion
              WHERE conversion_id_campaign = :c AND conversion_external_txid = 'TX-VIDE'"
        );
        $stmt->execute(['c' => $ctx['campaign_id']]);

        self::assertSame('33.0000', $stmt->fetchColumn());
    }

    /** `payout` reste prioritaire si les deux arrivent. */
    public function testPayoutPrimeSurAmount(): void
    {
        $ctx  = $this->makeCampaign('https://money.test/?c={clickid}');
        $clic = $this->clic($ctx);

        $this->get("/pb?clickid=$clic&txid=TX-DEUX&payout=7&amount=99&s={$ctx['secret']}");

        $stmt = $this->pdo->prepare(
            "SELECT conversion_payout FROM t_conversion
              WHERE conversion_id_campaign = :c AND conversion_external_txid = 'TX-DEUX'"
        );
        $stmt->execute(['c' => $ctx['campaign_id']]);

        self::assertSame('7.0000', $stmt->fetchColumn());
    }

    /**
     * Sans txid, on retombe sur « une conversion par clic ». C'est degrade :
     * cela interdit deux ventes sur un meme clic. Fige ici pour que le
     * compromis reste conscient.
     */
    public function testSansTxidUneSeuleConversionParClic(): void
    {
        $ctx  = $this->makeCampaign('https://money.test/?c={clickid}');
        $clic = $this->clic($ctx);

        $this->get("/pb?clickid=$clic&payout=10&s={$ctx['secret']}");
        $this->get("/pb?clickid=$clic&payout=25&s={$ctx['secret']}");

        self::assertSame(1, $this->compteConversions($ctx['campaign_id']));
    }
}
