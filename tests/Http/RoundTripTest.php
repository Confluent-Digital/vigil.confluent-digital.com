<?php

declare(strict_types=1);

namespace App\Tests\Http;

/**
 * Le parcours complet, de bout en bout.
 *
 * C'est LE test qui prouve la raison d'etre de Vigil : la plateforme qui a
 * emis le clic recupere SON identifiant a la fin, alors que le money site n'a
 * configure qu'une seule URL de retour.
 *
 *   plateforme --(cid=CD-xxx)--> Vigil --(clickid=ULID)--> money site
 *   money site --(postback)--> Vigil --(relais)--> plateforme (cid=CD-xxx)
 */
final class RoundTripTest extends HttpTestCase
{
    public function testLIdentifiantDeLaPlateformeFaitLAllerRetour(): void
    {
        // Une destination de relai qui renvoie le cid a l'expediteur.
        $ctx = $this->makeCampaign(
            $this->baseUrl() . '/money?clickid={clickid}&prenom={prenom}'
        );

        $this->pdo->prepare(
            'INSERT INTO t_campaign_postback
                 (cpb_id_campaign, cpb_name, cpb_url, cpb_method, cpb_on_status)
             VALUES (:c, :n, :u, \'GET\', \'approved\')'
        )->execute([
            'c' => $ctx['campaign_id'],
            'n' => 'PHPUNIT-plateforme',
            'u' => 'https://plateforme.test/pixel?cid={external_clickid}&sum={payout}',
        ]);

        // ── 1. La plateforme emet son identifiant ────────────────────────────
        $sonClic = 'CD-' . strtoupper(bin2hex(random_bytes(4)));

        // ── 2. Vigil : il le garde, et redirige avec le sien ─────────────────
        $r = $this->get('/c/' . $ctx['token'] . '?cid=' . $sonClic . '&prenom=Marie');

        self::assertSame(302, $r['status']);
        parse_str((string) parse_url($r['headers']['location'], PHP_URL_QUERY), $versLeMoneySite);

        $clickidVigil = (string) $versLeMoneySite['clickid'];
        self::assertTrue(\App\Core\Ulid::isValid($clickidVigil));
        self::assertNotSame($sonClic, $clickidVigil, 'Vigil donne SON identifiant au money site');
        self::assertSame('Marie', $versLeMoneySite['prenom'], 'le pre-remplissage traverse');

        usleep(300000);

        // Vigil a bien mis de cote l'identifiant de la plateforme.
        $stmt = $this->pdo->prepare(
            'SELECT click_external_id FROM t_click
              WHERE click_id_campaign = :c ORDER BY click_date DESC LIMIT 1'
        );
        $stmt->execute(['c' => $ctx['campaign_id']]);
        self::assertSame($sonClic, $stmt->fetchColumn());

        // ── 3 et 4. Le money site valide : UNE seule URL de retour ───────────
        $this->get(sprintf(
            '/pb?clickid=%s&txid=CMD-RT&payout=12.5&s=%s',
            $clickidVigil,
            $ctx['secret']
        ));

        // ── 5. Le relai porte l'identifiant de la PLATEFORME ─────────────────
        $stmt = $this->pdo->prepare(
            'SELECT q.pq_url FROM t_postback_queue q
               JOIN t_conversion v ON v.conversion_id = q.pq_id_conversion
              WHERE v.conversion_id_campaign = :c ORDER BY q.pq_id DESC LIMIT 1'
        );
        $stmt->execute(['c' => $ctx['campaign_id']]);
        $urlDuRelai = (string) $stmt->fetchColumn();

        self::assertNotSame('', $urlDuRelai, 'un relai doit avoir ete empile');
        parse_str((string) parse_url($urlDuRelai, PHP_URL_QUERY), $versLaPlateforme);

        self::assertSame(
            $sonClic,
            $versLaPlateforme['cid'],
            'la plateforme doit recevoir SON identifiant de depart — c\'est la raison d\'etre de Vigil'
        );
        self::assertSame('12.5000', $versLaPlateforme['sum']);
    }

    /**
     * Le probleme d'origine : plusieurs campagnes, un seul champ de postback
     * chez le money site. Chaque plateforme doit malgre tout recuperer le sien.
     */
    public function testDeuxCampagnesUnSeulPostbackChacuneSonIdentifiant(): void
    {
        $identifiants = [];

        foreach (['A', 'B'] as $lettre) {
            $ctx = $this->makeCampaign($this->baseUrl() . '/money?clickid={clickid}');

            $this->pdo->prepare(
                'INSERT INTO t_campaign_postback (cpb_id_campaign, cpb_name, cpb_url, cpb_method, cpb_on_status)
                 VALUES (:c, :n, :u, \'GET\', \'approved\')'
            )->execute([
                'c' => $ctx['campaign_id'],
                'n' => 'PHPUNIT-plateforme-' . $lettre,
                'u' => 'https://plateforme.test/pixel?cid={external_clickid}',
            ]);

            $sonClic = 'CD-' . $lettre . '-' . strtoupper(bin2hex(random_bytes(3)));

            $r = $this->get('/c/' . $ctx['token'] . '?cid=' . $sonClic);
            parse_str((string) parse_url($r['headers']['location'], PHP_URL_QUERY), $q);
            usleep(300000);

            // Le money site appelle TOUJOURS la meme URL : /pb.
            $this->get(sprintf('/pb?clickid=%s&txid=CMD-%s&payout=10&s=%s', $q['clickid'], $lettre, $ctx['secret']));

            $stmt = $this->pdo->prepare(
                'SELECT q.pq_url FROM t_postback_queue q
                   JOIN t_conversion v ON v.conversion_id = q.pq_id_conversion
                  WHERE v.conversion_id_campaign = :c ORDER BY q.pq_id DESC LIMIT 1'
            );
            $stmt->execute(['c' => $ctx['campaign_id']]);
            parse_str((string) parse_url((string) $stmt->fetchColumn(), PHP_URL_QUERY), $relai);

            $identifiants[$lettre] = ['emis' => $sonClic, 'recu' => $relai['cid'] ?? null];
        }

        foreach ($identifiants as $lettre => $paire) {
            self::assertSame(
                $paire['emis'],
                $paire['recu'],
                "la campagne $lettre doit recuperer son propre identifiant"
            );
        }

        self::assertNotSame(
            $identifiants['A']['recu'],
            $identifiants['B']['recu'],
            'les deux campagnes ne doivent pas recevoir le meme identifiant'
        );
    }
}
