<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use Throwable;

/**
 * Diagnostic et journalisation des liens morts.
 *
 * Appele UNIQUEMENT depuis la branche 404 de `/c` : on est deja hors du chemin
 * nominal, une requete de plus n'y coute rien. Sur le chemin nominal, rien de
 * tout ceci ne s'execute.
 */
final class LinkMiss
{
    public const RAISONS = [
        'token_inconnu'      => 'Jeton inconnu',
        'acces_suspendu'     => 'Accès du publisher suspendu',
        'campagne_suspendue' => 'Campagne en pause ou archivée',
        'campagne_expiree'   => 'Campagne terminée (date de fin dépassée)',
        'publisher_inactif'  => 'Publisher suspendu ou archivé',
        'token_malforme'     => 'Jeton malformé',
    ];

    /** Toutes les saisies aberrantes tiennent sous une seule ligne par jour. */
    public const TOKEN_MALFORME = '(malforme)';

    /** Format attendu d'un jeton : ce que `Token::generate(12)` produit. */
    public static function isWellFormed(string $token): bool
    {
        return $token !== '' && preg_match('/^[A-Za-z0-9]{6,24}$/', $token) === 1;
    }

    /**
     * Pourquoi ce jeton ne redirige-t-il pas ?
     *
     * `CampaignCache::get()` rend `null` pour cinq causes distinctes, qu'il ne
     * distingue pas — c'est normal, il est sur le chemin chaud. Ici on peut
     * poser la question, et c'est elle qui rend le probleme reparable :
     * « campagne en pause » se corrige en un clic, « jeton inconnu » non.
     *
     * @return array{raison: string, campagne: ?int, publisher: ?int}
     */
    public static function diagnose(PDO $pdo, string $token): array
    {
        $stmt = $pdo->prepare(
            'SELECT cp.cp_status, cp.cp_id_campaign, cp.cp_id_publisher,
                    c.campaign_status, c.campaign_date_stop,
                    p.publisher_status
               FROM t_campaign_publisher cp
               JOIN t_campaign  c ON c.campaign_id  = cp.cp_id_campaign
               JOIN t_publisher p ON p.publisher_id = cp.cp_id_publisher
              WHERE cp.cp_token = :t
              LIMIT 1'
        );
        $stmt->execute(['t' => $token]);
        $ligne = $stmt->fetch();

        if ($ligne === false) {
            return ['raison' => 'token_inconnu', 'campagne' => null, 'publisher' => null];
        }

        $campagne  = (int) $ligne['cp_id_campaign'];
        $publisher = (int) $ligne['cp_id_publisher'];

        // Ordre d'evaluation : du plus specifique au plus general, pour que le
        // libelle designe la chose a corriger et pas une consequence.
        $raison = match (true) {
            $ligne['cp_status'] !== 'active'        => 'acces_suspendu',
            $ligne['publisher_status'] !== 'active' => 'publisher_inactif',
            $ligne['campaign_status'] !== 'active'  => 'campagne_suspendue',
            $ligne['campaign_date_stop'] !== null
                && $ligne['campaign_date_stop'] < gmdate('Y-m-d') => 'campagne_expiree',
            default => 'token_inconnu',
        };

        return ['raison' => $raison, 'campagne' => $campagne, 'publisher' => $publisher];
    }

    /**
     * Enregistre le 404, agrege par (jeton, jour).
     *
     * N'echoue jamais bruyamment : un defaut de journalisation ne doit pas
     * transformer un 404 en 500.
     */
    public static function record(
        PDO $pdo,
        string $token,
        array $diagnostic,
        ?string $ip,
        ?string $referer
    ): void {
        try {
            $pdo->prepare(
                'INSERT INTO t_link_miss
                     (miss_token, miss_date, miss_reason, miss_count,
                      miss_id_campaign, miss_id_publisher,
                      miss_first_at, miss_last_at, miss_last_ip, miss_last_referer)
                 VALUES (:t, UTC_DATE(), :r, 1, :c, :p,
                         UTC_TIMESTAMP(), UTC_TIMESTAMP(), INET6_ATON(:ip), :ref)
                 ON DUPLICATE KEY UPDATE
                     miss_count        = miss_count + 1,
                     miss_reason       = VALUES(miss_reason),
                     miss_id_campaign  = VALUES(miss_id_campaign),
                     miss_id_publisher = VALUES(miss_id_publisher),
                     miss_last_at      = UTC_TIMESTAMP(),
                     miss_last_ip      = VALUES(miss_last_ip),
                     miss_last_referer = VALUES(miss_last_referer)'
            )->execute([
                't'   => $token,
                'r'   => $diagnostic['raison'],
                'c'   => $diagnostic['campagne'],
                'p'   => $diagnostic['publisher'],
                'ip'  => $ip,
                'ref' => $referer !== null && $referer !== ''
                    ? ClickContext::truncate($referer, 500) : null,
            ]);
        } catch (Throwable $e) {
            error_log('[vigil/c] journalisation du 404 impossible : ' . $e->getMessage());
        }
    }

    /**
     * Page rendue au visiteur.
     *
     * HTML statique, ecrit ici : le chemin chaud n'a ni Twig ni framework, et
     * ce n'est pas un 404 qui va justifier de les charger.
     *
     * Elle ne dit JAMAIS pourquoi. « Cette campagne est en pause » renseigne un
     * concurrent sur l'etat de vos operations ; le diagnostic reste dans le
     * journal, pour vous.
     */
    public static function page(): string
    {
        return <<<'HTML'
            <!doctype html>
            <html lang="fr">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex, nofollow">
            <title>Lien expiré</title>
            <style>
              :root { color-scheme: light dark; }
              body {
                margin: 0; min-height: 100vh;
                display: flex; align-items: center; justify-content: center;
                font: 16px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
                background: #fbfbfd; color: #14141c; padding: 1.5rem;
              }
              .box { max-width: 30rem; text-align: center; }
              .mark {
                width: 3rem; height: 3rem; margin: 0 auto 1.5rem;
                border-radius: 50%; background: #eef0fe; color: #4f46e5;
                display: flex; align-items: center; justify-content: center;
                font-size: 1.5rem; font-weight: 700;
              }
              h1 { font-size: 1.4rem; margin: 0 0 .75rem; letter-spacing: -.01em; }
              p { margin: 0 0 .5rem; color: #5f5f72; }
              .small { font-size: .85rem; color: #8a8a9e; margin-top: 1.75rem; }
              @media (prefers-color-scheme: dark) {
                body { background: #0d0d13; color: #e9e9f2; }
                p { color: #9494a8; }
                .mark { background: #1d1a3c; color: #8f88f8; }
                .small { color: #6f6f84; }
              }
            </style>
            </head>
            <body>
              <div class="box">
                <div class="mark">!</div>
                <h1>Ce lien n'est plus actif</h1>
                <p>L'offre a pris fin, ou l'adresse a été modifiée.</p>
                <p>Si vous l'avez reçu par courriel, une version plus récente
                   vous a peut-être déjà été envoyée.</p>
                <p class="small">Vous pouvez fermer cette page.</p>
              </div>
            </body>
            </html>
            HTML;
    }
}
