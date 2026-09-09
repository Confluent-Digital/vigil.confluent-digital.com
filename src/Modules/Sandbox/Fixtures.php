<?php

declare(strict_types=1);

namespace App\Modules\Sandbox;

use App\Core\CampaignCache;
use App\Core\Database;
use App\Core\PostbackRouter;
use App\Core\Sandbox;
use App\Core\Token;
use App\Core\Ulid;
use PDO;

/**
 * Jeu de donnees du bac a sable.
 *
 * Volontairement plus riche qu'un client et une campagne : le but est
 * d'exercer le processus entier — plusieurs clients, plusieurs publishers,
 * une matrice d'acces incomplete (pour voir que l'autorisation est l'absence
 * de jeton), une destination par campagne, et le pixel de conversion de deux
 * publishers.
 *
 * TOUT porte le prefixe `Sandbox::PREFIX`. La suppression s'y fie entierement :
 * rien ne doit etre cree sans lui, sinon la donnee d'essai survit au menage et
 * se melange aux chiffres reels.
 */
final class Fixtures
{
    /** @var array<int, array{nom: string, campagnes: array<int, array{nom: string, payout: float}>}> */
    private const CLIENTS = [
        ['nom' => 'Assurance Vacances', 'campagnes' => [
            ['nom' => 'Devis assurance voyage', 'payout' => 12.50],
            ['nom' => 'Assurance annulation',   'payout' => 8.00],
        ]],
        ['nom' => 'Energie Verte', 'campagnes' => [
            ['nom' => 'Simulation panneaux solaires', 'payout' => 22.00],
            ['nom' => 'Devis pompe a chaleur',        'payout' => 30.00],
        ]],
        ['nom' => 'Mutuelle Sante', 'campagnes' => [
            ['nom' => 'Comparateur mutuelle senior', 'payout' => 18.00],
        ]],
    ];

    /** Le troisieme n'a pas de pixel : on doit pouvoir voir la difference. */
    private const PUBLISHERS = [
        ['nom' => 'Emailer Alpha',    'pixel' => true],
        ['nom' => 'Regie Beta',       'pixel' => true],
        ['nom' => 'Affilie Gamma',    'pixel' => false],
        ['nom' => 'Plateforme Delta', 'pixel' => true],
    ];

    /**
     * Compte de connexion jetable, cree avec le jeu.
     *
     * Il existe pour une raison precise : sans lui, tester la connexion oblige
     * a emprunter un compte reel — et un enrolement TOTP ecrase ne se recupere
     * pas. Le mot de passe est tire au hasard et affiche UNE fois sur l'ecran
     * du bac a sable ; il n'y a donc pas de porte derobee a mot de passe connu,
     * et `destroy()` supprime le compte avec le reste.
     *
     * @return array{email: string, motdepasse: string}
     */
    public static function createTestUser(): array
    {
        $pdo        = Database::get();
        $email      = 'testeur@bac-a-sable.test';
        $motDePasse = Token::generate(14);

        $pdo->prepare('DELETE FROM t_user WHERE user_email = :e')->execute(['e' => $email]);

        $pdo->prepare(
            'INSERT INTO t_user (user_email, user_password, user_name, user_role)
             VALUES (:e, :p, :n, \'admin\')'
        )->execute([
            'e' => $email,
            'p' => password_hash($motDePasse, PASSWORD_BCRYPT),
            'n' => Sandbox::PREFIX . ' Testeur',
        ]);

        return ['email' => $email, 'motdepasse' => $motDePasse];
    }

    /**
     * Cree le jeu complet.
     *
     * @return array{clients: int, publishers: int, campagnes: int, acces: int, destinations: int}
     */
    public static function create(): array
    {
        $pdo = Database::get();
        self::destroy();

        $compteurs = ['clients' => 0, 'publishers' => 0, 'campagnes' => 0, 'acces' => 0, 'destinations' => 0];

        // ── Publishers ──────────────────────────────────────────────────────
        $publishers = [];
        foreach (self::PUBLISHERS as $p) {
            $pdo->prepare(
                'INSERT INTO t_publisher (publisher_name, publisher_token, publisher_contact_email)
                 VALUES (:n, :t, :e)'
            )->execute([
                'n' => Sandbox::PREFIX . ' ' . $p['nom'],
                't' => 'sbx' . Token::generate(7),
                'e' => strtolower(str_replace(' ', '.', $p['nom'])) . '@exemple.test',
            ]);
            $id = (int) $pdo->lastInsertId();
            $publishers[] = ['id' => $id, 'nom' => $p['nom'], 'pixel' => $p['pixel']];
            $compteurs['publishers']++;

            if ($p['pixel']) {
                // Portee publisher : cpb_id_campaign reste NULL, le pixel se
                // declenche sur toutes ses conversions.
                $pdo->prepare(
                    'INSERT INTO t_campaign_postback
                        (cpb_id_campaign, cpb_id_publisher, cpb_name, cpb_url, cpb_method, cpb_on_status)
                     VALUES (NULL, :p, :n, :u, \'GET\', \'approved\')'
                )->execute([
                    'p' => $id,
                    'n' => Sandbox::PREFIX . ' Pixel ' . $p['nom'],
                    // Adresse INTERNE : c'est le worker qui livre ce relai,
                    // depuis le conteneur, jamais le navigateur.
                    'u' => Sandbox::internalBaseUrl()
                         . '/sandbox/platform?src=' . rawurlencode($p['nom'])
                         . '&cid={external_clickid}&payout={payout}&st={status}',
                ]);
                $compteurs['destinations']++;
            }
        }

        // ── Clients, campagnes, acces ───────────────────────────────────────
        $indexAcces = 0;
        foreach (self::CLIENTS as $c) {
            $pdo->prepare('INSERT INTO t_client (client_name, client_contact_email) VALUES (:n, :e)')
                ->execute([
                    'n' => Sandbox::PREFIX . ' ' . $c['nom'],
                    'e' => 'ops@' . strtolower(str_replace(' ', '-', $c['nom'])) . '.test',
                ]);
            $clientId = (int) $pdo->lastInsertId();
            $compteurs['clients']++;

            foreach ($c['campagnes'] as $camp) {
                // Adresse PUBLIQUE : c'est le navigateur du visiteur qui suit
                // cette redirection. L'adresse interne (`vigil_nginx`) ne
                // resout pas hors des conteneurs — le visiteur atterrirait sur
                // une erreur de resolution de nom.
                $dest = Sandbox::publicBaseUrl() . '/sandbox/money-site'
                    . '?clickid={clickid}&prenom={prenom}&nom={nom}&email={email}&cp={cp}&tel={tel}';

                $pdo->prepare(
                    'INSERT INTO t_campaign
                        (campaign_id_client, campaign_name, campaign_status, campaign_dest_url,
                         campaign_payout, campaign_currency, campaign_postback_secret)
                     VALUES (:c, :n, \'active\', :u, :p, \'EUR\', :k)'
                )->execute([
                    'c' => $clientId,
                    'n' => Sandbox::PREFIX . ' ' . $camp['nom'],
                    'u' => $dest,
                    'p' => $camp['payout'],
                    'k' => Token::secret(),
                ]);
                $campaignId = (int) $pdo->lastInsertId();
                $compteurs['campagnes']++;

                // La plateforme externe de cette campagne : portee campagne,
                // toutes sources confondues.
                $pdo->prepare(
                    'INSERT INTO t_campaign_postback
                        (cpb_id_campaign, cpb_id_publisher, cpb_name, cpb_url, cpb_method, cpb_on_status)
                     VALUES (:c, NULL, :n, :u, \'GET\', \'approved,chargeback\')'
                )->execute([
                    'c' => $campaignId,
                    'n' => Sandbox::PREFIX . ' Plateforme externe',
                    // Adresse INTERNE : livree par le worker, cf. ci-dessus.
                    'u' => Sandbox::internalBaseUrl()
                         . '/sandbox/platform?src=plateforme&cid={external_clickid}'
                         . '&sum={payout}&st={status}&tx={txid}',
                ]);
                $compteurs['destinations']++;

                // Matrice d'acces volontairement INCOMPLETE : trois publishers
                // sur quatre par campagne, en rotation. C'est ce qui rend
                // visible que l'autorisation n'est pas un controle mais
                // l'absence de jeton.
                foreach ($publishers as $i => $pub) {
                    if (($i + $indexAcces) % 4 === 3) {
                        continue;
                    }
                    $pdo->prepare(
                        'INSERT INTO t_campaign_publisher (cp_id_campaign, cp_id_publisher, cp_token)
                         VALUES (:c, :p, :t)'
                    )->execute([
                        'c' => $campaignId, 'p' => $pub['id'], 't' => 'sbx' . Token::generate(9),
                    ]);
                    $compteurs['acces']++;
                }
                $indexAcces++;
            }
        }

        CampaignCache::invalidate();

        return $compteurs;
    }

    /**
     * Genere du trafic historique : des clics repartis sur sept jours, et des
     * conversions sur une partie d'entre eux.
     *
     * Ecriture directe en base, sans passer par `/c` : le but est de remplir
     * les ecrans, pas d'exercer le chemin chaud — c'est le bouton « Cliquer sur
     * le lien » qui fait ca, fidelement. Generer deux cents clics par HTTP
     * prendrait une minute pour le meme resultat visuel.
     *
     * @return array{clics: int, conversions: int, relais: int}
     */
    public static function traffic(int $clics = 220): array
    {
        $pdo = Database::get();

        $acces = $pdo->query(
            'SELECT cp.cp_id_campaign AS campagne, cp.cp_id_publisher AS publisher,
                    c.campaign_payout AS payout
               FROM t_campaign_publisher cp
               JOIN t_campaign c ON c.campaign_id = cp.cp_id_campaign
               JOIN t_client cl ON cl.client_id = c.campaign_id_client
              WHERE cl.client_name LIKE ' . $pdo->quote(Sandbox::PREFIX . '%')
        )->fetchAll();

        if ($acces === []) {
            return ['clics' => 0, 'conversions' => 0, 'relais' => 0];
        }

        $insertClic = $pdo->prepare(
            'INSERT INTO t_click
                (click_id, click_date, click_id_campaign, click_id_publisher,
                 click_ip, click_user_agent, click_external_id, click_sub1,
                 click_is_unique, click_is_bot, click_has_prefill)
             VALUES (:i, :d, :c, :p, INET6_ATON(:ip), :ua, :e, :s, :u, :b, :f)'
        );

        $insertConv = $pdo->prepare(
            'INSERT INTO t_conversion
                (conversion_id_click, conversion_click_date, conversion_id_campaign,
                 conversion_id_publisher, conversion_external_txid, conversion_status,
                 conversion_payout, conversion_currency, conversion_date)
             VALUES (:i, :d, :c, :p, :t, :s, :y, \'EUR\', :dt)'
        );

        $sources = ['newsletter-01', 'newsletter-02', 'promo-ete', 'relance-j3', 'base-froide'];
        $agents  = [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) AppleWebKit/605.1.15',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36',
        ];

        $nbConversions = 0;
        $nbRelais      = 0;

        for ($n = 0; $n < $clics; $n++) {
            $a = $acces[random_int(0, count($acces) - 1)];

            // Reparti sur sept jours, avec plus de trafic en journee.
            $decalage = random_int(0, 7 * 24 * 3600);
            $quand    = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify('-' . $decalage . ' seconds');

            $ulid = Ulid::generate((int) ($quand->format('U') . substr($quand->format('u'), 0, 3)));
            $bot  = random_int(1, 100) <= 6;

            $insertClic->execute([
                'i'  => Ulid::toBinary($ulid),
                'd'  => $quand->format('Y-m-d H:i:s.v'),
                'c'  => $a['campagne'],
                'p'  => $a['publisher'],
                'ip' => '203.0.113.' . random_int(1, 254),
                'ua' => $agents[array_rand($agents)],
                'e'  => 'EXT-' . strtoupper(Token::generate(8)),
                's'  => $sources[array_rand($sources)],
                'u'  => random_int(1, 100) <= 78 ? 1 : 0,
                'b'  => $bot ? 1 : 0,
                'f'  => random_int(1, 100) <= 60 ? 1 : 0,
            ]);

            // Environ 9 % de conversion sur les clics humains.
            if ($bot || random_int(1, 100) > 9) {
                continue;
            }

            // Le postback arrive apres le clic, parfois bien apres.
            $converti = $quand->modify('+' . random_int(60, 7200) . ' seconds');
            if ($converti > new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
                $converti = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            }

            $tirage = random_int(1, 100);
            $statut = $tirage <= 74 ? 'approved'
                : ($tirage <= 86 ? 'pending' : ($tirage <= 95 ? 'rejected' : 'chargeback'));

            $insertConv->execute([
                'i'  => Ulid::toBinary($ulid),
                'd'  => $quand->format('Y-m-d H:i:s.v'),
                'c'  => $a['campagne'],
                'p'  => $a['publisher'],
                't'  => 'CMD-' . strtoupper(Token::generate(8)),
                's'  => $statut,
                'y'  => $a['payout'],
                'dt' => $converti->format('Y-m-d H:i:s.v'),
            ]);
            $nbConversions++;

            $conversionId = (int) $pdo->lastInsertId();

            $nbRelais += PostbackRouter::enqueue(
                $pdo,
                $conversionId,
                (int) $a['campagne'],
                (int) $a['publisher'],
                $statut,
                [
                    'clickid'          => $ulid,
                    'external_clickid' => 'EXT-' . strtoupper(Token::generate(8)),
                    'campaign_id'      => $a['campagne'],
                    'publisher_id'     => $a['publisher'],
                    'payout'           => number_format((float) $a['payout'], 4, '.', ''),
                    'status'           => $statut,
                    'txid'             => 'CMD-' . strtoupper(Token::generate(8)),
                    'timestamp'        => $converti->getTimestamp(),
                ]
            );
        }

        return ['clics' => $clics, 'conversions' => $nbConversions, 'relais' => $nbRelais];
    }

    /** Supprime tout ce qui porte le prefixe. Rien d'autre. */
    public static function destroy(): void
    {
        $pdo    = Database::get();
        $motif  = Sandbox::PREFIX . '%';

        $campagnes = $pdo->prepare(
            'SELECT c.campaign_id FROM t_campaign c
               JOIN t_client cl ON cl.client_id = c.campaign_id_client
              WHERE cl.client_name LIKE :m OR c.campaign_name LIKE :m2'
        );
        $campagnes->execute(['m' => $motif, 'm2' => $motif]);

        foreach ($campagnes->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $pdo->prepare(
                'DELETE q FROM t_postback_queue q
                   JOIN t_conversion v ON v.conversion_id = q.pq_id_conversion
                  WHERE v.conversion_id_campaign = :i'
            )->execute(['i' => $id]);

            foreach ([
                't_conversion'         => 'conversion_id_campaign',
                't_click'              => 'click_id_campaign',
                't_campaign_publisher' => 'cp_id_campaign',
                't_campaign_postback'  => 'cpb_id_campaign',
                't_stats_hourly'       => 'stats_id_campaign',
            ] as $table => $colonne) {
                $pdo->prepare("DELETE FROM $table WHERE $colonne = :i")->execute(['i' => $id]);
            }

            $pdo->prepare('DELETE FROM t_campaign WHERE campaign_id = :i')->execute(['i' => $id]);
        }

        // Les pixels de portee publisher n'ont pas de campagne : ils survivent
        // a la boucle ci-dessus, il faut les retirer explicitement.
        $pdo->prepare(
            'DELETE FROM t_campaign_postback
              WHERE cpb_id_publisher IN (SELECT publisher_id FROM t_publisher WHERE publisher_name LIKE :m)'
        )->execute(['m' => $motif]);

        // Le compte de test jetable part avec le reste : aucun compte a mot de
        // passe connu ne doit survivre au menage.
        $pdo->prepare('DELETE FROM t_user_recovery_code WHERE recovery_id_user IN
                       (SELECT user_id FROM t_user WHERE user_name LIKE :m)')->execute(['m' => $motif]);
        $pdo->prepare('DELETE FROM t_user_email_code WHERE emailcode_id_user IN
                       (SELECT user_id FROM t_user WHERE user_name LIKE :m)')->execute(['m' => $motif]);
        $pdo->prepare('DELETE FROM t_login_attempt WHERE attempt_id_user IN
                       (SELECT user_id FROM t_user WHERE user_name LIKE :m)')->execute(['m' => $motif]);
        $pdo->prepare('DELETE FROM t_user WHERE user_name LIKE :m')->execute(['m' => $motif]);

        $pdo->prepare('DELETE FROM t_publisher WHERE publisher_name LIKE :m')->execute(['m' => $motif]);
        $pdo->prepare('DELETE FROM t_client WHERE client_name LIKE :m')->execute(['m' => $motif]);

        CampaignCache::invalidate();
    }

    /** @return array<string, int> ce que contient actuellement le bac a sable */
    public static function summary(): array
    {
        $pdo   = Database::get();
        $motif = $pdo->quote(Sandbox::PREFIX . '%');

        $un = static fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();

        return [
            'clients'     => $un("SELECT COUNT(*) FROM t_client WHERE client_name LIKE $motif"),
            'publishers'  => $un("SELECT COUNT(*) FROM t_publisher WHERE publisher_name LIKE $motif"),
            'campagnes'   => $un("SELECT COUNT(*) FROM t_campaign WHERE campaign_name LIKE $motif"),
            'acces'       => $un("SELECT COUNT(*) FROM t_campaign_publisher cp
                                    JOIN t_campaign c ON c.campaign_id = cp.cp_id_campaign
                                   WHERE c.campaign_name LIKE $motif"),
            'pixels'      => $un("SELECT COUNT(*) FROM t_campaign_postback pb
                                    JOIN t_publisher p ON p.publisher_id = pb.cpb_id_publisher
                                   WHERE p.publisher_name LIKE $motif"),
            'clics'       => $un("SELECT COUNT(*) FROM t_click cl
                                    JOIN t_campaign c ON c.campaign_id = cl.click_id_campaign
                                   WHERE c.campaign_name LIKE $motif"),
            'conversions' => $un("SELECT COUNT(*) FROM t_conversion v
                                    JOIN t_campaign c ON c.campaign_id = v.conversion_id_campaign
                                   WHERE c.campaign_name LIKE $motif"),
            'relais'      => $un("SELECT COUNT(*) FROM t_postback_queue q
                                    JOIN t_conversion v ON v.conversion_id = q.pq_id_conversion
                                    JOIN t_campaign c ON c.campaign_id = v.conversion_id_campaign
                                   WHERE c.campaign_name LIKE $motif"),
        ];
    }
}
