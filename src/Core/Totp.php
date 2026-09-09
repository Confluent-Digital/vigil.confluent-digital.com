<?php

declare(strict_types=1);

namespace App\Core;

/**
 * TOTP — RFC 6238. HMAC-SHA1, 6 chiffres, pas de 30 secondes : les valeurs
 * qu'attendent Google Authenticator, 1Password, Bitwarden et Aegis.
 */
final class Totp
{
    private const DIGITS = 6;
    private const PERIOD = 30;

    /**
     * Tolerance, en pas de 30 s, de part et d'autre du pas courant. Une fenetre
     * de 1 accepte l'horloge du telephone decalee d'une demi-minute — cas
     * frequent — sans elargir la surface d'attaque au-dela de 90 secondes.
     */
    private const WINDOW = 1;

    /** Secret de 160 bits, la taille recommandee par la RFC pour HMAC-SHA1. */
    public static function generateSecret(): string
    {
        return Base32::encode(random_bytes(20));
    }

    public static function code(string $secretBase32, ?int $step = null): string
    {
        $step ??= self::currentStep();
        $key    = Base32::decode($secretBase32);

        // Compteur sur 8 octets, gros-boutiste.
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);

        // Troncature dynamique : les 4 bits de poids faible du dernier octet
        // designent l'offset de lecture.
        $offset = ord($hash[19]) & 0x0F;
        $value  = ((ord($hash[$offset]) & 0x7F) << 24)
                | ((ord($hash[$offset + 1]) & 0xFF) << 16)
                | ((ord($hash[$offset + 2]) & 0xFF) << 8)
                | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Verifie un code et rend le pas de temps consomme, ou null.
     *
     * L'appelant DOIT enregistrer ce pas et refuser tout pas inferieur ou egal
     * au suivant : sans cela, un code intercepte reste rejouable pendant sa
     * fenetre de validite.
     */
    public static function verify(string $secretBase32, string $submitted, ?int $lastStep = null): ?int
    {
        $submitted = preg_replace('/\D/', '', $submitted) ?? '';
        if (strlen($submitted) !== self::DIGITS) {
            return null;
        }

        $current = self::currentStep();

        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            $step = $current + $offset;

            if ($lastStep !== null && $step <= $lastStep) {
                continue;
            }

            // hash_equals : la comparaison d'un code se fait a temps constant
            // comme celle d'un secret.
            if (hash_equals(self::code($secretBase32, $step), $submitted)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * URI otpauth:// a encoder dans le QR code d'enrolement.
     *
     * Le libelle porte la date d'enrolement. Une application
     * d'authentification ne sait pas qu'un secret a ete revoque : elle continue
     * d'afficher l'ancienne entree, sous un nom identique a la nouvelle. Sans
     * ce marqueur, deux entrees « Vigil : untel@… » cohabitent sans qu'on
     * puisse dire laquelle est vivante — et l'ancienne rend des codes refuses.
     */
    public static function provisioningUri(string $secretBase32, string $account, string $issuer): string
    {
        // Date au format ISO, sans barre oblique ni parenthese : le chemin
        // d'une URI otpauth se decoupe sur « / », et plusieurs applications le
        // decoupent AVANT de decoder les %2F. Un « 08/09/2026 » encode y casse
        // le libelle, voire tout l'import.
        $account .= ' ' . gmdate('Y-m-d');

        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($account),
            $secretBase32,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD
        );
    }

    public static function currentStep(): int
    {
        return intdiv(time(), self::PERIOD);
    }

    /** Secret groupe par 4 caracteres, pour la saisie manuelle. */
    public static function formatForDisplay(string $secretBase32): string
    {
        return trim(chunk_split($secretBase32, 4, ' '));
    }
}
