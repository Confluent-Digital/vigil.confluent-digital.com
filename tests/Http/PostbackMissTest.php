<?php

declare(strict_types=1);

namespace App\Tests\Http;

/**
 * `/pb` rend toujours 200, meme quand il refuse. Sans trace, brancher une
 * campagne se fait a l'aveugle : le money site voit « HTTP 200 » et croit que
 * ca marche, alors qu'aucune conversion n'est creee.
 *
 * Ces tests verrouillent la visibilite du refus — c'est ce qui transforme
 * « ca ne marche pas » en « il manque &s= ».
 */
final class PostbackMissTest extends HttpTestCase
{
    private function refus(string $raison): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM t_postback_miss WHERE pmiss_reason = :r AND pmiss_date = UTC_DATE()'
        );
        $stmt->execute(['r' => $raison]);

        return $stmt->fetch() ?: [];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('DELETE FROM t_postback_miss');
    }

    public function testClickidAbsent(): void
    {
        self::assertSame(200, $this->get('/pb')['status'], 'le money site recoit toujours 200');
        usleep(200000);

        self::assertNotEmpty($this->refus('clickid_absent'));
    }

    public function testClickidMalforme(): void
    {
        $this->get('/pb?clickid=pas-un-ulid&txid=T1');
        usleep(200000);

        self::assertNotEmpty($this->refus('clickid_malforme'));
    }

    public function testClickidInconnu(): void
    {
        $this->get('/pb?clickid=' . \App\Core\Ulid::generate() . '&txid=T1');
        usleep(200000);

        self::assertNotEmpty($this->refus('clickid_inconnu'));
    }

    /**
     * Le cas le plus frequent d'un premier branchement, et le plus deroutant :
     * tout semble fonctionner, rien n'est enregistre.
     */
    public function testSecretInvalideEstTraceAvecSaCampagne(): void
    {
        $ctx  = $this->makeCampaign('https://money.test/?c={clickid}');
        $r    = $this->get('/c/' . $ctx['token']);
        parse_str((string) parse_url($r['headers']['location'], PHP_URL_QUERY), $p);
        usleep(300000);

        $reponse = $this->get('/pb?clickid=' . $p['c'] . '&txid=T1&s=mauvais');
        usleep(300000);

        self::assertSame(200, $reponse['status']);
        self::assertSame(0, $this->countRows(
            't_conversion',
            'conversion_id_campaign = :c',
            ['c' => $ctx['campaign_id']]
        ), 'aucune conversion ne doit etre creee');

        $refus = $this->refus('secret_invalide');
        self::assertNotEmpty($refus);
        self::assertSame(
            $ctx['campaign_id'],
            (int) $refus['pmiss_id_campaign'],
            'la campagne doit etre identifiee : c\'est elle qu\'on va corriger'
        );
    }

    /** Le secret n'a rien a faire dans une table que le back-office affiche. */
    public function testLeSecretEstMasqueDansLaTrace(): void
    {
        $ctx = $this->makeCampaign('https://money.test/?c={clickid}');
        $r   = $this->get('/c/' . $ctx['token']);
        parse_str((string) parse_url($r['headers']['location'], PHP_URL_QUERY), $p);
        usleep(300000);

        $this->get('/pb?clickid=' . $p['c'] . '&txid=T1&s=SECRET-EN-CLAIR');
        usleep(300000);

        $refus = $this->refus('secret_invalide');

        self::assertNotEmpty($refus);
        self::assertStringNotContainsString('SECRET-EN-CLAIR', (string) $refus['pmiss_last_query']);
        self::assertStringContainsString('MASQUE', (string) $refus['pmiss_last_query']);
    }

    /** Agrege : un money site en boucle ne doit pas faire enfler la table. */
    public function testLesRefusSontAgreges(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->get('/pb?clickid=pas-un-ulid&txid=T' . $i);
        }
        usleep(400000);

        self::assertSame(
            1,
            $this->countRows('t_postback_miss', "pmiss_reason = 'clickid_malforme'"),
            'une ligne par (jour, cause, campagne), pas une par appel'
        );
        self::assertGreaterThanOrEqual(15, (int) $this->refus('clickid_malforme')['pmiss_count']);
    }

    /** Un postback valide ne doit evidemment rien inscrire ici. */
    public function testUnPostbackValideNeLaisseAucunRefus(): void
    {
        $ctx = $this->makeCampaign('https://money.test/?c={clickid}');
        $r   = $this->get('/c/' . $ctx['token']);
        parse_str((string) parse_url($r['headers']['location'], PHP_URL_QUERY), $p);
        usleep(300000);

        $this->get('/pb?clickid=' . $p['c'] . '&txid=T-OK&payout=10&s=' . $ctx['secret']);
        usleep(300000);

        self::assertSame(1, $this->countRows(
            't_conversion',
            'conversion_id_campaign = :c',
            ['c' => $ctx['campaign_id']]
        ));
        self::assertSame(0, $this->countRows('t_postback_miss'));
    }
}
