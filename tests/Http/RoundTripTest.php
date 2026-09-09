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
    /**
     * Toute macro declaree dans `MacroEngine::MACROS` doit etre alimentee par
     * le contexte de `pb.php`, pas seulement par celui de `c.php`.
     *
     * Deux ne l'etaient pas — `{publisher_token}` et `{ua}`. Elles
     * fonctionnaient sur la destination de campagne et partaient **vides** sur
     * le relai : le partenaire recevait `&src=` sans valeur, sans erreur, et
     * sans que rien ne le signale. Une macro annoncee et non alimentee est pire
     * qu'une macro absente, puisque le formulaire l'accepte.
     */
    public function testToutesLesMacrosDeTrackingSontAlimenteesSurLeRelai(): void
    {
        // `country` est exclu : il depend d'une resolution GeoIP absente en
        // developpement, et son vide est legitime. Les subs sont fournis par le
        // clic ci-dessous, donc attendus non vides.
        $exclues = ['country'];
        $macros  = array_values(array_diff(\App\Core\MacroEngine::TRACKING_MACROS, $exclues));

        $gabarit = [];
        foreach ($macros as $m) {
            $gabarit[] = $m . '={' . $m . '}';
        }

        $ctx = $this->makeCampaign($this->baseUrl() . '/money?clickid={clickid}');

        $this->pdo->prepare(
            'INSERT INTO t_campaign_postback
                 (cpb_id_campaign, cpb_name, cpb_url, cpb_method, cpb_on_status)
             VALUES (:c, :n, :u, \'GET\', \'approved\')'
        )->execute([
            'c' => $ctx['campaign_id'],
            'n' => 'PHPUNIT-macros',
            'u' => 'https://plateforme.test/pixel?' . implode('&', $gabarit),
        ]);

        $r = $this->get('/c/' . $ctx['token'] . '?cid=EXT-1&s1=a&s2=b&s3=c&s4=d&s5=e');
        parse_str((string) parse_url($r['headers']['location'], PHP_URL_QUERY), $q);
        usleep(300000);

        $this->get(sprintf(
            '/pb?clickid=%s&txid=TX-MACROS&payout=12&revenue=3&s=%s',
            $q['clickid'],
            $ctx['secret']
        ));

        $stmt = $this->pdo->prepare(
            'SELECT q.pq_url FROM t_postback_queue q
               JOIN t_conversion v ON v.conversion_id = q.pq_id_conversion
              WHERE v.conversion_id_campaign = :c ORDER BY q.pq_id DESC LIMIT 1'
        );
        $stmt->execute(['c' => $ctx['campaign_id']]);
        $url = (string) $stmt->fetchColumn();

        self::assertNotSame('', $url, 'un relai doit avoir ete empile');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $recu);

        $vides = [];
        foreach ($macros as $m) {
            if (($recu[$m] ?? '') === '') {
                $vides[] = '{' . $m . '}';
            }
        }

        self::assertSame([], $vides, 'macros declarees mais vides sur le relai : ' . implode(', ', $vides));
    }

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

    /**
     * Le cas d'usage qui justifie le produit, de bout en bout : **un seul
     * secret pour tout un client**, quel que soit le nombre de campagnes.
     *
     * Sans secret au niveau du client, le `&s=` differait d'une campagne a
     * l'autre : le money site devait en configurer un par campagne, ce qu'il ne
     * sait justement pas faire. On avait deplace le probleme, pas resolu.
     */
    public function testUnSeulSecretDeClientOuvreToutesSesCampagnes(): void
    {
        $secretClient = 'cli' . bin2hex(random_bytes(8));

        // Campagne A cree le client ; campagne B s'y rattache. Aucune des deux
        // n'a de secret propre : seul celui du client peut ouvrir.
        $a = $this->makeCampaign(
            $this->baseUrl() . '/money?clickid={clickid}',
            ['secret' => false, 'client_secret' => $secretClient]
        );
        $b = $this->makeCampaign(
            $this->baseUrl() . '/money?clickid={clickid}',
            ['secret' => false, 'client_id' => $a['client_id']]
        );

        foreach ([['A', $a], ['B', $b]] as [$nom, $ctx]) {
            $r = $this->get('/c/' . $ctx['token'] . '?cid=EXT-' . $nom);
            parse_str((string) parse_url($r['headers']['location'], PHP_URL_QUERY), $q);
            usleep(300000);

            // Le MEME secret, pour les deux campagnes.
            $this->get(sprintf(
                '/pb?clickid=%s&txid=TX-%s&amount=9&s=%s',
                $q['clickid'],
                $nom,
                $secretClient
            ));

            self::assertSame(
                1,
                $this->countRows(
                    't_conversion',
                    'conversion_id_campaign = :c',
                    ['c' => $ctx['campaign_id']]
                ),
                "la campagne $nom doit avoir enregistre sa conversion avec le secret du client"
            );
        }

        // Et un secret qui n'est ni celui du client ni celui d'une campagne
        // reste refuse — le partage ne doit pas devenir une porte ouverte.
        $r = $this->get('/c/' . $a['token'] . '?cid=EXT-REFUS');
        parse_str((string) parse_url($r['headers']['location'], PHP_URL_QUERY), $q);
        usleep(300000);

        $this->get("/pb?clickid={$q['clickid']}&txid=TX-REFUS&amount=9&s=mauvais");

        self::assertSame(
            1,
            $this->countRows('t_conversion', 'conversion_id_campaign = :c', ['c' => $a['campaign_id']]),
            'un mauvais secret ne doit rien creer de plus'
        );
    }
}
