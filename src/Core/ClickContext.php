<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Lecture des parametres d'un clic. Partage entre `c.php` et le back-office
 * (testeur de lien), pour qu'il n'existe qu'une seule definition de « quel
 * parametre est le click id de la plateforme externe ».
 */
final class ClickContext
{
    /** Noms sous lesquels les plateformes externes transmettent leur click id. */
    private const EXTERNAL_ID_KEYS = [
        'cid', 'clickid', 'click_id', 'subid', 'sub_id', 'ext_id', 'transaction_id',
    ];

    private const SUB_KEYS = [
        1 => ['s1', 'sub1'],
        2 => ['s2', 'sub2'],
        3 => ['s3', 'sub3'],
        4 => ['s4', 'sub4'],
        5 => ['s5', 'sub5'],
    ];

    /** Fragments de user-agent qui trahissent un robot. */
    private const BOT_MARKERS = [
        'bot', 'crawler', 'spider', 'curl/', 'wget', 'python-requests',
        'headlesschrome', 'phantomjs', 'facebookexternalhit', 'slackbot',
        'ahrefs', 'semrush', 'bingpreview', 'go-http-client', 'okhttp',
    ];

    /** @param array<string, mixed> $query */
    public static function externalId(array $query): ?string
    {
        foreach (self::EXTERNAL_ID_KEYS as $key) {
            if (isset($query[$key]) && is_scalar($query[$key]) && (string) $query[$key] !== '') {
                return self::truncate((string) $query[$key], 128);
            }
        }
        return null;
    }

    /**
     * @param  array<string, mixed> $query
     * @return array<int, string|null> indexe de 1 a 5
     */
    public static function subs(array $query): array
    {
        $out = [];
        foreach (self::SUB_KEYS as $i => $aliases) {
            $out[$i] = null;
            foreach ($aliases as $key) {
                if (isset($query[$key]) && is_scalar($query[$key]) && (string) $query[$key] !== '') {
                    $out[$i] = self::truncate((string) $query[$key], 255);
                    break;
                }
            }
        }
        return $out;
    }

    public static function isBot(string $userAgent): bool
    {
        if ($userAgent === '') {
            return true;
        }
        $ua = strtolower($userAgent);
        foreach (self::BOT_MARKERS as $marker) {
            if (str_contains($ua, $marker)) {
                return true;
            }
        }
        return false;
    }

    /**
     * IP reelle du visiteur. nginx la transmet en X-Real-IP ; sans elle, tous
     * les clics seraient enregistres avec l'IP du reverse proxy.
     *
     * @param array<string, mixed> $server
     */
    public static function clientIp(array $server): ?string
    {
        foreach (['HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            $raw = $server[$key] ?? null;
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            // X-Forwarded-For peut chainer plusieurs IPs : la premiere est le
            // client, les suivantes sont les proxys traverses.
            $candidate = trim(explode(',', $raw)[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * MariaDB tourne en STRICT_TRANS_TABLES : une valeur trop longue est
     * REJETEE, pas tronquee. Sans cette coupe cote PHP, un partenaire bavard
     * provoque une erreur 1406, donc un clic perdu.
     */
    public static function truncate(string $value, int $max): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }
}
