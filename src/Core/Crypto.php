<?php

declare(strict_types=1);

namespace App\Core;

use SodiumException;

/**
 * Chiffrement symetrique des secrets stockes en base (secret TOTP).
 *
 * En clair, une lecture SQL — un dump egare, une injection ailleurs dans
 * l'application — suffirait a fabriquer des codes valides pour n'importe quel
 * compte : le second facteur ne protegerait plus de rien.
 */
final class Crypto
{
    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        // Le nonce voyage avec le message : il n'est pas secret, il doit
        // seulement ne jamais se repeter pour une meme cle.
        return $nonce . sodium_crypto_secretbox($plain, $nonce, self::key());
    }

    public static function decrypt(string $cipher): ?string
    {
        if (strlen($cipher) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce   = substr($cipher, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $payload = substr($cipher, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        try {
            $plain = sodium_crypto_secretbox_open($payload, $nonce, self::key());
        } catch (SodiumException) {
            return null;
        }

        // false = message altere ou mauvaise cle. On rend null plutot que de
        // laisser passer une valeur douteuse.
        return $plain === false ? null : $plain;
    }

    /**
     * Cle derivee d'APP_SECRET. Changer APP_SECRET rend tous les secrets TOTP
     * illisibles : les comptes devront se reinscrire. C'est voulu — une
     * rotation de cle doit etre un acte conscient.
     */
    private static function key(): string
    {
        $secret = Env::get('APP_SECRET', '');
        if ($secret === '' || $secret === null) {
            throw new \RuntimeException(
                'APP_SECRET absent du .env : impossible de chiffrer les secrets TOTP.'
            );
        }

        return hash('sha256', 'vigil.totp.v1|' . $secret, true);
    }
}
