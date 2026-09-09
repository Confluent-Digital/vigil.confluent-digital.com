<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Journal d'authentification et plafonnement des tentatives.
 *
 * Sert deux buts a la fois : ralentir le devinement, et laisser une trace.
 * L'ecran de securite s'appuie sur ce journal pour montrer qui s'est connecte
 * avec le repli par courriel — un contournement du second facteur ne doit
 * jamais passer inapercu.
 */
final class LoginGuard
{
    /** Tentatives infructueuses tolerees, par couple (compte, IP), sur la fenetre. */
    private const MAX_FAILURES = 10;
    private const WINDOW_MINUTES = 15;

    /** Renvois de code par courriel autorises par heure et par compte. */
    private const MAX_EMAIL_CODES_PER_HOUR = 5;

    public static function record(
        string $email,
        ?int $userId,
        string $event,
        ?string $ip,
        ?string $userAgent = null
    ): void {
        Database::get()->prepare(
            'INSERT INTO t_login_attempt
                 (attempt_email, attempt_id_user, attempt_event, attempt_ip, attempt_user_agent)
             VALUES (:email, :user, :event, INET6_ATON(:ip), :ua)'
        )->execute([
            'email' => mb_substr($email, 0, 190),
            'user'  => $userId,
            'event' => $event,
            'ip'    => $ip,
            'ua'    => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
        ]);
    }

    /** Le compte (ou l'IP) est-il temporairement bloque ? */
    public static function isLocked(string $email, ?string $ip): bool
    {
        $stmt = Database::get()->prepare(
            "SELECT COUNT(*) FROM t_login_attempt
              WHERE attempt_event IN ('password_fail', 'totp_fail', 'email_code_fail', 'recovery_fail')
                AND attempt_date > UTC_TIMESTAMP() - INTERVAL :w MINUTE
                AND (attempt_email = :email OR attempt_ip = INET6_ATON(:ip))"
        );
        $stmt->execute(['w' => self::WINDOW_MINUTES, 'email' => $email, 'ip' => $ip]);

        return (int) $stmt->fetchColumn() >= self::MAX_FAILURES;
    }

    /** Minutes restantes avant deblocage, pour l'afficher plutot que de laisser deviner. */
    public static function lockMinutesLeft(string $email, ?string $ip): int
    {
        $stmt = Database::get()->prepare(
            "SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), MAX(attempt_date) + INTERVAL :w MINUTE)
               FROM t_login_attempt
              WHERE attempt_event IN ('password_fail', 'totp_fail', 'email_code_fail', 'recovery_fail')
                AND attempt_date > UTC_TIMESTAMP() - INTERVAL :w2 MINUTE
                AND (attempt_email = :email OR attempt_ip = INET6_ATON(:ip))"
        );
        $stmt->execute([
            'w' => self::WINDOW_MINUTES, 'w2' => self::WINDOW_MINUTES,
            'email' => $email, 'ip' => $ip,
        ]);

        return max(1, (int) $stmt->fetchColumn());
    }

    /**
     * Le repli par courriel est le maillon faible assume du dispositif : on
     * borne au moins la cadence a laquelle il peut etre sollicite.
     */
    public static function canSendEmailCode(int $userId): bool
    {
        $stmt = Database::get()->prepare(
            "SELECT COUNT(*) FROM t_login_attempt
              WHERE attempt_id_user = :id AND attempt_event = 'email_code_sent'
                AND attempt_date > UTC_TIMESTAMP() - INTERVAL 1 HOUR"
        );
        $stmt->execute(['id' => $userId]);

        return (int) $stmt->fetchColumn() < self::MAX_EMAIL_CODES_PER_HOUR;
    }

    /** Efface les echecs apres une connexion aboutie. */
    public static function clearFailures(string $email): void
    {
        Database::get()->prepare(
            "DELETE FROM t_login_attempt
              WHERE attempt_email = :email
                AND attempt_event IN ('password_fail', 'totp_fail', 'email_code_fail', 'recovery_fail')"
        )->execute(['email' => $email]);
    }
}
