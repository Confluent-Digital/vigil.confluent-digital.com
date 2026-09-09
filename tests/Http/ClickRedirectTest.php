<?php

declare(strict_types=1);

namespace App\Tests\Http;

final class ClickRedirectTest extends HttpTestCase
{
    /**
     * L'invariant le plus couteux du systeme s'il casse : un 301 est mis en
     * cache par le navigateur, les clics suivants du meme visiteur ne repassent
     * plus par Vigil et ne sont JAMAIS comptes. La panne est silencieuse et ne
     * se voit que des semaines plus tard, dans l'ecart de reporting.
     */
    public function testRedirection302EtJamais301(): void
    {
        $ctx = $this->makeCampaign('https://money.test/?c={clickid}');

        $r = $this->get('/c/' . $ctx['token']);

        self::assertSame(302, $r['status'], 'un 301 serait mis en cache');
        self::assertStringStartsWith('https://money.test/?c=', $r['headers']['location'] ?? '');
    }

    public function testEnTetesAntiCache(): void
    {
        $ctx = $this->makeCampaign('https://money.test/?c={clickid}');

        $r = $this->get('/c/' . $ctx['token']);

        self::assertStringContainsString('no-store', $r['headers']['cache-control'] ?? '');
        self::assertSame('no-referrer', $r['headers']['referrer-policy'] ?? '');
    }

    public function testLeClickidEstUnUlidValide(): void
    {
        $ctx = $this->makeCampaign('https://money.test/?c={clickid}');

        parse_str((string) parse_url($this->get('/c/' . $ctx['token'])['headers']['location'], PHP_URL_QUERY), $params);

        self::assertTrue(\App\Core\Ulid::isValid($params['c'] ?? ''));
    }

    public function testTokenInconnuRend404(): void
    {
        self::assertSame(404, $this->get('/c/nexistepas42')['status']);
    }

    public function testCampagneEnPauseRend404(): void
    {
        $ctx = $this->makeCampaign('https://money.test/?c={clickid}', ['status' => 'paused']);

        self::assertSame(404, $this->get('/c/' . $ctx['token'])['status']);
    }

    public function testAccesPublisherEnPauseRend404(): void
    {
        $ctx = $this->makeCampaign('https://money.test/?c={clickid}', ['cp_status' => 'paused']);

        self::assertSame(404, $this->get('/c/' . $ctx['token'])['status']);
    }

    /** Les douze champs du kit mailing traversent jusqu'au money site. */
    public function testLePreRemplissageTraverse(): void
    {
        $ctx = $this->makeCampaign(
            'https://money.test/?c={clickid}&prenom={prenom}&nom={nom}&email={email}&cp={cp}&tel={tel}'
        );

        $r = $this->get('/c/' . $ctx['token']
            . '?prenom=Marie%20Claire&nom=Durand&email=marie.durand%40exemple.fr&cp=75011&tel=0612345678');

        parse_str((string) parse_url($r['headers']['location'], PHP_URL_QUERY), $p);

        self::assertSame('Marie Claire', $p['prenom']);
        self::assertSame('Durand', $p['nom']);
        self::assertSame('marie.durand@exemple.fr', $p['email']);
        self::assertSame('75011', $p['cp']);
        self::assertSame('0612345678', $p['tel']);
    }

    /**
     * La promesse centrale du pre-remplissage : ces valeurs traversent, elles
     * ne sont pas conservees. On l'exige ici sur la vraie table, apres un vrai
     * clic HTTP — pas seulement sur le filtre pris isolement.
     */
    public function testAucuneDonneePersonnelleNAtterritEnBase(): void
    {
        $ctx = $this->makeCampaign('https://money.test/?c={clickid}&n={nom}');

        $this->get('/c/' . $ctx['token']
            . '?nom=Durand&prenom=Marie&email=marie.durand%40exemple.fr&tel=0612345678&cp=75011&utm_source=nl');

        usleep(300000);

        $stmt = $this->pdo->prepare(
            'SELECT click_raw_query, click_has_prefill FROM t_click
              WHERE click_id_campaign = :c ORDER BY click_date DESC LIMIT 1'
        );
        $stmt->execute(['c' => $ctx['campaign_id']]);
        $ligne = $stmt->fetch();

        self::assertNotFalse($ligne, 'le clic doit avoir ete enregistre');
        self::assertSame(1, (int) $ligne['click_has_prefill'], 'le marqueur, lui, est conserve');

        $json = (string) $ligne['click_raw_query'];
        self::assertStringContainsString('utm_source', $json, 'les parametres non personnels restent');

        foreach (['Durand', 'Marie', 'marie.durand', '0612345678', '75011'] as $donnee) {
            self::assertStringNotContainsString($donnee, $json, "« $donnee » ne doit pas etre persiste");
        }
    }

    public function testLeClicEstEnregistreAvecSonContexte(): void
    {
        $ctx = $this->makeCampaign('https://money.test/?c={clickid}');

        $this->get('/c/' . $ctx['token'] . '?s1=source-A&cid=EXT-777');
        usleep(300000);

        $stmt = $this->pdo->prepare(
            'SELECT click_sub1, click_external_id, click_is_bot FROM t_click
              WHERE click_id_campaign = :c ORDER BY click_date DESC LIMIT 1'
        );
        $stmt->execute(['c' => $ctx['campaign_id']]);
        $ligne = $stmt->fetch();

        self::assertSame('source-A', $ligne['click_sub1']);
        self::assertSame('EXT-777', $ligne['click_external_id']);
        self::assertSame(0, (int) $ligne['click_is_bot'], 'un vrai user-agent n\'est pas un bot');
    }
}
