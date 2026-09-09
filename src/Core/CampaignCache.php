<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Resolution du couple campagne x publisher a partir du token du lien.
 *
 * Sur le chemin chaud, un SELECT par clic doublerait le cout d'une requete pour
 * une donnee qui change quelques fois par jour. On passe donc par APCu, avec
 * repli sur la base. Toute modification de campagne, d'acces ou de destination
 * DOIT appeler `invalidate()` — sinon elle met jusqu'a TTL secondes a prendre.
 */
final class CampaignCache
{
    private const TTL = 60;
    private const PREFIX = 'vigil.link.';

    /** @var array<string, array<string, mixed>|false> Cache de process. */
    private static array $local = [];

    /**
     * @return array<string, mixed>|null null = token inconnu, campagne ou acces
     *                                   en pause. Le negatif est cache aussi :
     *                                   sinon un token errone en circulation
     *                                   taperait la base a chaque appel.
     */
    public static function get(string $token): ?array
    {
        if (array_key_exists($token, self::$local)) {
            return self::$local[$token] ?: null;
        }

        $key = self::PREFIX . $token;

        if (function_exists('apcu_fetch')) {
            $hit = apcu_fetch($key, $ok);
            if ($ok) {
                self::$local[$token] = $hit;
                return $hit ?: null;
            }
        }

        $row = self::fetchFromDatabase($token);
        self::$local[$token] = $row ?? false;

        if (function_exists('apcu_store')) {
            apcu_store($key, $row ?? false, self::TTL);
        }

        return $row;
    }

    public static function invalidate(?string $token = null): void
    {
        self::$local = [];

        if (!function_exists('apcu_delete')) {
            return;
        }

        if ($token !== null) {
            apcu_delete(self::PREFIX . $token);
            return;
        }

        // Purge large : une campagne touche potentiellement N acces, et on ne
        // veut pas que l'appelant ait a connaitre tous les tokens concernes.
        if (function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
        }
    }

    /** @return array<string, mixed>|null */
    private static function fetchFromDatabase(string $token): ?array
    {
        $sql = <<<'SQL'
            SELECT cp.cp_id, cp.cp_token, cp.cp_payout,
                   c.campaign_id, c.campaign_name, c.campaign_dest_url,
                   c.campaign_payout, c.campaign_currency,
                   c.campaign_postback_secret, c.campaign_postback_ips,
                   c.campaign_postback_response, c.campaign_id_client,
                   p.publisher_id, p.publisher_token
              FROM t_campaign_publisher cp
              JOIN t_campaign  c ON c.campaign_id  = cp.cp_id_campaign
              JOIN t_publisher p ON p.publisher_id = cp.cp_id_publisher
             WHERE cp.cp_token    = :token
               AND cp.cp_status   = 'active'
               AND c.campaign_status  = 'active'
               AND p.publisher_status = 'active'
               AND (c.campaign_date_stop IS NULL OR c.campaign_date_stop >= UTC_DATE())
             LIMIT 1
        SQL;

        $stmt = Database::get()->prepare($sql);
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        // Le payout de l'acces prime sur celui de la campagne quand il existe.
        $row['payout'] = $row['cp_payout'] ?? $row['campaign_payout'];

        return $row;
    }
}
