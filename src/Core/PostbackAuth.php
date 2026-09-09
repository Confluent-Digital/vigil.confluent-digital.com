<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Authentification du postback entrant.
 *
 * Sans elle, quiconque connait un clickid peut crediter des conversions — qui
 * partent ensuite en S2S chez le partenaire et se facturent. C'est la faille la
 * plus couteuse du systeme.
 */
final class PostbackAuth
{
    /**
     * @param  array<string, mixed> $campaign
     * @return true|string true si autorise, sinon le motif du refus
     */
    public static function check(array $campaign, ?string $providedSecret, ?string $ip): true|string
    {
        $secret = (string) ($campaign['campaign_postback_secret'] ?? '');
        $ips    = trim((string) ($campaign['campaign_postback_ips'] ?? ''));

        if ($secret === '' && $ips === '') {
            // Campagne sans aucun controle : on laisse passer pour ne pas
            // casser un branchement en cours, mais c'est une anomalie que le
            // back-office doit signaler en alerte.
            return true;
        }

        if ($secret !== '') {
            // hash_equals et jamais === : une comparaison a temps variable
            // laisse deviner le secret octet par octet.
            if ($providedSecret === null || !hash_equals($secret, $providedSecret)) {
                return 'secret invalide';
            }
        }

        if ($ips !== '') {
            if ($ip === null || !self::ipAllowed($ip, $ips)) {
                return 'ip non autorisee (' . ($ip ?? 'inconnue') . ')';
            }
        }

        return true;
    }

    /** @param string $csv liste CSV d'IPs ou de CIDR */
    public static function ipAllowed(string $ip, string $csv): bool
    {
        foreach (array_filter(array_map('trim', explode(',', $csv))) as $entry) {
            if (!str_contains($entry, '/')) {
                if ($entry === $ip) {
                    return true;
                }
                continue;
            }
            if (self::inCidr($ip, $entry)) {
                return true;
            }
        }
        return false;
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);

        $ipBin     = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = (int) $bits;
        $max  = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $max) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        $rest  = $bits % 8;

        if ($bytes > 0 && strncmp($ipBin, $subnetBin, $bytes) !== 0) {
            return false;
        }
        if ($rest === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $rest)) - 1) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }
}
