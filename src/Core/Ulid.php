<?php

declare(strict_types=1);

namespace App\Core;

/**
 * ULID — 48 bits d'horodatage en millisecondes puis 80 bits d'aleatoire,
 * encodes en base32 de Crockford sur 26 caracteres.
 *
 * Choisi plutot qu'un auto-increment parce qu'un identifiant sequentiel en
 * clair se devine : n'importe qui pourrait forger des conversions en enumerant.
 *
 * Choisi plutot qu'un UUIDv4 parce que le prefixe temporel le rend croissant,
 * donc sans fragmentation d'index — et surtout parce qu'il permet de retrouver
 * la partition d'un clic sans rien stocker de plus (cf. `timestamp()`).
 */
final class Ulid
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    private const ENCODED_LENGTH = 26;

    private static int $lastMs = 0;
    /** @var int[] Les 10 octets d'aleatoire de la derniere generation. */
    private static array $lastRandom = [];

    public static function generate(?int $ms = null): string
    {
        $ms ??= (int) (microtime(true) * 1000);

        if ($ms === self::$lastMs && self::$lastRandom !== []) {
            // Meme milliseconde : on incremente l'aleatoire precedent plutot
            // que d'en tirer un nouveau, pour que l'ordre de generation reste
            // l'ordre lexicographique. Sans cela, deux clics de la meme
            // milliseconde pourraient s'inverser dans un tri par identifiant.
            self::$lastRandom = self::increment(self::$lastRandom);
        } else {
            self::$lastMs = $ms;
            self::$lastRandom = array_values(unpack('C*', random_bytes(10)));
        }

        return self::encodeTime($ms) . self::encodeRandom(self::$lastRandom);
    }

    /** Horodatage en millisecondes porte par l'ULID. */
    public static function timestamp(string $ulid): ?int
    {
        $ulid = strtoupper($ulid);
        if (!self::isValid($ulid)) {
            return null;
        }

        $ms = 0;
        for ($i = 0; $i < 10; $i++) {
            $ms = $ms * 32 + strpos(self::ALPHABET, $ulid[$i]);
        }

        return $ms;
    }

    public static function isValid(string $ulid): bool
    {
        return strlen($ulid) === self::ENCODED_LENGTH
            && strspn(strtoupper($ulid), self::ALPHABET) === self::ENCODED_LENGTH;
    }

    /** 16 octets, pour un BINARY(16) en base. */
    public static function toBinary(string $ulid): ?string
    {
        $ulid = strtoupper($ulid);
        if (!self::isValid($ulid)) {
            return null;
        }

        $bits = '';
        for ($i = 0; $i < self::ENCODED_LENGTH; $i++) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $ulid[$i])), 5, '0', STR_PAD_LEFT);
        }
        // 26 x 5 = 130 bits ; les 2 premiers sont du remplissage.
        $bits = substr($bits, 2);

        $out = '';
        for ($i = 0; $i < 128; $i += 8) {
            $out .= chr((int) bindec(substr($bits, $i, 8)));
        }

        return $out;
    }

    public static function fromBinary(string $binary): ?string
    {
        if (strlen($binary) !== 16) {
            return null;
        }

        $bits = '';
        foreach (unpack('C*', $binary) as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }
        $bits = '00' . $bits;

        $out = '';
        for ($i = 0; $i < 130; $i += 5) {
            $out .= self::ALPHABET[(int) bindec(substr($bits, $i, 5))];
        }

        return $out;
    }

    private static function encodeTime(int $ms): string
    {
        $out = '';
        for ($i = 9; $i >= 0; $i--) {
            $out = self::ALPHABET[$ms % 32] . $out;
            $ms = intdiv($ms, 32);
        }
        return $out;
    }

    /** @param int[] $bytes */
    private static function encodeRandom(array $bytes): string
    {
        $bits = '';
        foreach ($bytes as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        for ($i = 0; $i < 80; $i += 5) {
            $out .= self::ALPHABET[(int) bindec(substr($bits, $i, 5))];
        }
        return $out;
    }

    /**
     * @param  int[] $bytes
     * @return int[]
     */
    private static function increment(array $bytes): array
    {
        for ($i = count($bytes) - 1; $i >= 0; $i--) {
            if ($bytes[$i] < 255) {
                $bytes[$i]++;
                return $bytes;
            }
            $bytes[$i] = 0;
        }
        // Debordement des 80 bits dans la meme milliseconde : hors d'atteinte
        // en pratique, mais on repart sur du neuf plutot que de rendre 0.
        return array_values(unpack('C*', random_bytes(10)));
    }
}
