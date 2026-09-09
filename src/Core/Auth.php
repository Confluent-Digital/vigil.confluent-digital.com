<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session du back-office.
 *
 * Le scope d'un utilisateur `publisher` est porte ici, pas dans l'interface :
 * filtrer seulement a l'affichage laisserait un IDOR ouvert sur les clics et
 * les conversions des autres.
 */
final class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => ($_SERVER['HTTPS'] ?? '') !== ''
                || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
        ]);
        session_name('vigil_session');
        session_start();
    }

    /**
     * Verifie le mot de passe et ouvre une session EN ATTENTE.
     *
     * Le mot de passe seul n'authentifie plus : il ne fait qu'ouvrir la porte
     * du second facteur. `check()` reste faux tant que celui-ci n'a pas ete
     * franchi, et c'est lui que consulte AuthMiddleware.
     *
     * @return array<string, mixed>|null la ligne utilisateur, ou null
     */
    public static function attemptPassword(string $email, string $password): ?array
    {
        $stmt = Database::get()->prepare(
            'SELECT user_id, user_email, user_name, user_password, user_role, user_id_publisher,
                    user_totp_secret, user_totp_enabled_at, user_totp_last_step
               FROM t_user WHERE user_email = :email AND user_status = \'active\' LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        // password_verify est appele meme sans utilisateur trouve, sur un hash
        // factice : sans cela, le temps de reponse revele quels e-mails existent.
        $hash = $user['user_password'] ?? '$2y$12$' . str_repeat('.', 53);
        if (!password_verify($password, $hash) || $user === false) {
            return null;
        }

        // L'identifiant de session change des le mot de passe valide, puis a
        // nouveau a l'authentification complete : un identifiant obtenu avant
        // la connexion ne doit jamais devenir un identifiant authentifie.
        session_regenerate_id(true);
        $_SESSION['pending'] = [
            'id'    => (int) $user['user_id'],
            'email' => $user['user_email'],
            'since' => time(),
        ];
        unset($_SESSION['user']);

        return $user;
    }

    /** Ouvre la session complete. A n'appeler qu'apres le second facteur. */
    public static function completeLogin(array $user): void
    {
        session_regenerate_id(true);
        unset($_SESSION['pending'], $_SESSION['enroll_secret']);

        $_SESSION['user'] = [
            'id'           => (int) $user['user_id'],
            'email'        => $user['user_email'],
            'name'         => $user['user_name'],
            'role'         => $user['user_role'],
            'id_publisher' => $user['user_id_publisher'] !== null
                ? (int) $user['user_id_publisher'] : null,
        ];

        Database::get()->prepare('UPDATE t_user SET user_date_login = UTC_TIMESTAMP() WHERE user_id = :id')
            ->execute(['id' => $user['user_id']]);
    }

    /**
     * Utilisateur en attente de second facteur.
     *
     * L'etape expire au bout de dix minutes : une session a demi ouverte,
     * laissee sur un poste partage, ne doit pas rester exploitable.
     *
     * @return array<string, mixed>|null
     */
    public static function pendingUser(): ?array
    {
        $pending = $_SESSION['pending'] ?? null;
        if ($pending === null) {
            return null;
        }

        if (time() - (int) $pending['since'] > 600) {
            unset($_SESSION['pending'], $_SESSION['enroll_secret']);
            return null;
        }

        $stmt = Database::get()->prepare(
            'SELECT user_id, user_email, user_name, user_role, user_id_publisher,
                    user_totp_secret, user_totp_enabled_at, user_totp_last_step
               FROM t_user WHERE user_id = :id AND user_status = \'active\' LIMIT 1'
        );
        $stmt->execute(['id' => $pending['id']]);

        return $stmt->fetch() ?: null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /** @return array<string, mixed>|null */
    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function check(): bool
    {
        return isset($_SESSION['user']);
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? '') === 'admin';
    }

    /** Non-null pour un compte publisher : toute requete doit etre scopee dessus. */
    public static function publisherScope(): ?int
    {
        $user = self::user();
        return ($user['role'] ?? '') === 'publisher' ? $user['id_publisher'] : null;
    }
}
