<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Base32 RFC 4648 — l'encodage qu'attendent les applications
 * d'authentification pour un secret TOTP.
 */
final class Base32
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function encode(string $binary): string
    {
        if ($binary === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($binary) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[(int) bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        // Pas de remplissage `=` : les applications d'authentification
        // l'acceptent rarement dans une URI otpauth.
        return $out;
    }

    public static function decode(string $base32): string
    {
        $base32 = strtoupper(str_replace([' ', '=', '-'], '', $base32));
        if ($base32 === '') {
            return '';
        }

        $bits = '';
        for ($i = 0, $n = strlen($base32); $i < $n; $i++) {
            $index = strpos(self::ALPHABET, $base32[$i]);
            if ($index === false) {
                continue;
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr((int) bindec($chunk));
            }
        }

        return $out;
    }
}
