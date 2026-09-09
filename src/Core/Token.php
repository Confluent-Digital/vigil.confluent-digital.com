<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Identifiants opaques : token de lien, token de publisher, secret de postback.
 *
 * Alphabet sans les caracteres qui se confondent a la lecture (0/O, 1/l/I) :
 * ces valeurs sont recopiees a la main dans des interfaces de partenaires.
 */
final class Token
{
    private const ALPHABET = '23456789abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';

    public static function generate(int $length = 12): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }
        return $out;
    }

    /** Secret de postback : pas destine a etre recopie, donc pleine entropie. */
    public static function secret(): string
    {
        return bin2hex(random_bytes(16));
    }
}
