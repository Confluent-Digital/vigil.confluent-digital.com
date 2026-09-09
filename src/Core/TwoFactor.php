<?php

declare(strict_types=1);

namespace App\Core;

use BaconQrCode\Encoder\Encoder;
use PDO;

/**
 * Second facteur : enrolement TOTP, verification, codes de secours, repli par
 * courriel.
 *
 * Le repli par courriel est un choix assume : il rend la connexion possible
 * depuis n'importe quel appareil, mais il ramene la securite de l'ensemble a
 * celle de la boite mail — qui suffit alors a se connecter. Les garde-fous
 * ci-dessous limitent la portee de ce choix sans le remettre en cause :
 * envoi uniquement vers l'adresse du compte, code a usage unique, duree
 * courte, plafond de tentatives, et notification de l'utilisateur a chaque
 * emploi du repli.
 */
final class TwoFactor
{
    public const EMAIL_CODE_TTL_MINUTES = 10;
    public const EMAIL_CODE_MAX_ATTEMPTS = 5;
    public const RECOVERY_CODE_COUNT = 8;

    // ── Enrolement ──────────────────────────────────────────────────────────

    public static function isEnrolled(array $user): bool
    {
        return !empty($user['user_totp_enabled_at']);
    }

    /** Secret provisoire, garde en session tant qu'il n'est pas confirme. */
    public static function beginEnrollment(): string
    {
        return Totp::generateSecret();
    }

    /**
     * Confirme l'enrolement : le code saisi prouve que l'application a bien
     * enregistre le meme secret. Sans cette preuve, un utilisateur qui a mal
     * scanne se retrouverait verrouille des la connexion suivante.
     *
     * @return string[]|null les codes de secours, ou null si le code est faux
     */
    public static function completeEnrollment(int $userId, string $secret, string $submitted): ?array
    {
        $step = Totp::verify($secret, $submitted);
        if ($step === null) {
            return null;
        }

        $pdo = Database::get();
        $pdo->prepare(
            'UPDATE t_user
                SET user_totp_secret = :secret, user_totp_enabled_at = UTC_TIMESTAMP(),
                    user_totp_last_step = :step
              WHERE user_id = :id'
        )->execute([
            'secret' => Crypto::encrypt($secret),
            'step'   => $step,
            'id'     => $userId,
        ]);

        return self::regenerateRecoveryCodes($userId);
    }

    // ── Verification TOTP ───────────────────────────────────────────────────

    public static function verifyTotp(array $user, string $submitted): bool
    {
        $secret = self::secretOf($user);
        if ($secret === null) {
            return false;
        }

        $last = $user['user_totp_last_step'] !== null ? (int) $user['user_totp_last_step'] : null;
        $step = Totp::verify($secret, $submitted, $last);

        if ($step === null) {
            return false;
        }

        // Le pas consomme est enregistre immediatement : c'est ce qui empeche
        // le rejeu du meme code pendant sa fenetre de validite.
        Database::get()
            ->prepare('UPDATE t_user SET user_totp_last_step = :step WHERE user_id = :id')
            ->execute(['step' => $step, 'id' => $user['user_id']]);

        return true;
    }

    public static function secretOf(array $user): ?string
    {
        $cipher = $user['user_totp_secret'] ?? null;
        if ($cipher === null || $cipher === '') {
            return null;
        }
        if (is_resource($cipher)) {
            $cipher = stream_get_contents($cipher);
        }

        return Crypto::decrypt((string) $cipher);
    }

    // ── Codes de secours ────────────────────────────────────────────────────

    /** @return string[] les codes en clair, affiches une seule fois */
    public static function regenerateRecoveryCodes(int $userId): array
    {
        $pdo = Database::get();
        $pdo->prepare('DELETE FROM t_user_recovery_code WHERE recovery_id_user = :id')
            ->execute(['id' => $userId]);

        $insert = $pdo->prepare(
            'INSERT INTO t_user_recovery_code (recovery_id_user, recovery_hash) VALUES (:id, :hash)'
        );

        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $code    = strtolower(Token::generate(5) . '-' . Token::generate(5));
            $codes[] = $code;
            // Hashe : un code de secours vaut un mot de passe.
            $insert->execute(['id' => $userId, 'hash' => password_hash($code, PASSWORD_BCRYPT)]);
        }

        return $codes;
    }

    public static function consumeRecoveryCode(int $userId, string $submitted): bool
    {
        $submitted = strtolower(trim($submitted));
        if ($submitted === '') {
            return false;
        }

        $pdo  = Database::get();
        $stmt = $pdo->prepare(
            'SELECT recovery_id, recovery_hash FROM t_user_recovery_code
              WHERE recovery_id_user = :id AND recovery_used_at IS NULL'
        );
        $stmt->execute(['id' => $userId]);

        foreach ($stmt->fetchAll() as $row) {
            if (password_verify($submitted, $row['recovery_hash'])) {
                $pdo->prepare(
                    'UPDATE t_user_recovery_code SET recovery_used_at = UTC_TIMESTAMP()
                      WHERE recovery_id = :id'
                )->execute(['id' => $row['recovery_id']]);

                return true;
            }
        }

        return false;
    }

    public static function remainingRecoveryCodes(int $userId): int
    {
        $stmt = Database::get()->prepare(
            'SELECT COUNT(*) FROM t_user_recovery_code
              WHERE recovery_id_user = :id AND recovery_used_at IS NULL'
        );
        $stmt->execute(['id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    // ── Repli par courriel ──────────────────────────────────────────────────

    /**
     * Emet un code et l'envoie a l'adresse DU COMPTE — jamais a une adresse
     * fournie dans la requete, ce qui ferait de ce repli un moyen de detourner
     * n'importe quel compte.
     */
    public static function sendEmailCode(array $user, ?string $ip): bool
    {
        $pdo  = Database::get();
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Les codes precedents sont invalides : deux codes valides en meme
        // temps doublent les chances d'un tirage au hasard.
        $pdo->prepare(
            'UPDATE t_user_email_code SET emailcode_used_at = UTC_TIMESTAMP()
              WHERE emailcode_id_user = :id AND emailcode_used_at IS NULL'
        )->execute(['id' => $user['user_id']]);

        $pdo->prepare(
            'INSERT INTO t_user_email_code
                 (emailcode_id_user, emailcode_hash, emailcode_expires_at, emailcode_ip)
             VALUES (:id, :hash, UTC_TIMESTAMP() + INTERVAL :ttl MINUTE, INET6_ATON(:ip))'
        )->execute([
            'id'   => $user['user_id'],
            'hash' => password_hash($code, PASSWORD_BCRYPT),
            'ttl'  => self::EMAIL_CODE_TTL_MINUTES,
            'ip'   => $ip,
        ]);

        $name = htmlspecialchars((string) $user['user_name'], ENT_QUOTES, 'UTF-8');
        $html = sprintf(
            '<p>Bonjour %s,</p>
             <p>Votre code de connexion à Vigil :</p>
             <p style="font-size:28px;font-weight:700;letter-spacing:.2em;font-family:monospace">%s</p>
             <p>Il est valable %d minutes et ne sert qu\'une fois.</p>
             <hr>
             <p style="color:#666;font-size:13px">
               Ce code a été demandé depuis l\'adresse %s.
               <strong>Si ce n\'est pas vous, votre mot de passe est connu d\'un tiers :
               changez-le immédiatement et prévenez un administrateur.</strong>
             </p>',
            $name,
            $code,
            self::EMAIL_CODE_TTL_MINUTES,
            htmlspecialchars($ip ?? 'inconnue', ENT_QUOTES, 'UTF-8')
        );

        return Mailer::send((string) $user['user_email'], 'Vigil — code de connexion', $html);
    }

    public static function verifyEmailCode(int $userId, string $submitted): bool
    {
        $submitted = preg_replace('/\D/', '', $submitted) ?? '';
        if (strlen($submitted) !== 6) {
            return false;
        }

        $pdo  = Database::get();
        $stmt = $pdo->prepare(
            'SELECT emailcode_id, emailcode_hash, emailcode_attempts
               FROM t_user_email_code
              WHERE emailcode_id_user = :id AND emailcode_used_at IS NULL
                AND emailcode_expires_at > UTC_TIMESTAMP()
           ORDER BY emailcode_id DESC LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return false;
        }

        // Plafond de tentatives : six chiffres se devinent en un million
        // d'essais, ce qui est a portee sans cette borne.
        if ((int) $row['emailcode_attempts'] >= self::EMAIL_CODE_MAX_ATTEMPTS) {
            $pdo->prepare(
                'UPDATE t_user_email_code SET emailcode_used_at = UTC_TIMESTAMP()
                  WHERE emailcode_id = :id'
            )->execute(['id' => $row['emailcode_id']]);

            return false;
        }

        $pdo->prepare(
            'UPDATE t_user_email_code SET emailcode_attempts = emailcode_attempts + 1
              WHERE emailcode_id = :id'
        )->execute(['id' => $row['emailcode_id']]);

        if (!password_verify($submitted, $row['emailcode_hash'])) {
            return false;
        }

        $pdo->prepare(
            'UPDATE t_user_email_code SET emailcode_used_at = UTC_TIMESTAMP()
              WHERE emailcode_id = :id'
        )->execute(['id' => $row['emailcode_id']]);

        return true;
    }

    /**
     * Previent l'utilisateur qu'un repli a servi. Le contournement du TOTP ne
     * doit jamais etre silencieux : c'est ce qui permet de detecter qu'une
     * boite mail a ete compromise.
     */
    public static function notifyFallbackUsed(array $user, ?string $ip): void
    {
        Mailer::send(
            (string) $user['user_email'],
            'Vigil — connexion sans votre application d\'authentification',
            sprintf(
                '<p>Bonjour %s,</p>
                 <p>Une connexion à Vigil vient d\'aboutir en utilisant le <strong>code reçu
                 par courriel</strong>, sans votre application d\'authentification.</p>
                 <p>Adresse IP : %s — le %s UTC.</p>
                 <p>Si ce n\'est pas vous, changez votre mot de passe et prévenez un
                 administrateur : quelqu\'un a accès à votre boîte mail.</p>',
                htmlspecialchars((string) $user['user_name'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($ip ?? 'inconnue', ENT_QUOTES, 'UTF-8'),
                gmdate('d/m/Y H:i')
            )
        );
    }

    // ── QR code ─────────────────────────────────────────────────────────────

    /**
     * QR code en SVG, genere cote serveur. Aucun service externe : envoyer un
     * secret TOTP a une API de generation d'images le divulguerait.
     */
    public static function qrCodeSvg(string $uri, int $size = 220): string
    {
        $matrix = Encoder::encode($uri, \BaconQrCode\Common\ErrorCorrectionLevel::M())
            ->getMatrix();

        $width = $matrix->getWidth();

        // Zone de silence de 4 modules de chaque cote : c'est le minimum impose
        // par la specification QR. En dessous, beaucoup de lecteurs de
        // telephone echouent a accrocher le motif — sans message d'erreur, le
        // code « ne marche pas », simplement.
        $marge  = 4;
        $module = $size / ($width + 2 * $marge);

        $rects = '';
        for ($y = 0; $y < $width; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if ($matrix->get($x, $y) === 1) {
                    $rects .= sprintf(
                        '<rect x="%.3f" y="%.3f" width="%.3f" height="%.3f"/>',
                        ($x + $marge) * $module,
                        ($y + $marge) * $module,
                        $module + 0.01,
                        $module + 0.01
                    );
                }
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" role="img" aria-label="QR code d\'enrolement">'
            . '<rect width="%d" height="%d" fill="#fff"/><g fill="#000">%s</g></svg>',
            $size, $size, $size, $size, $size, $size, $rects
        );
    }
}
