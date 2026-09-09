<?php

declare(strict_types=1);

/**
 * Remet a zero la verification en deux etapes d'un compte.
 *
 *   php bin/reset-2fa.php <email>            affiche ce qui serait fait
 *   php bin/reset-2fa.php <email> --confirme execute
 *
 * A utiliser quand quelqu'un a perdu son telephone ET ses codes de secours.
 *
 * Cette commande existe parce que l'operation se faisait jusqu'ici en SQL au
 * fil de l'eau — c'est comme ca qu'un enrolement reel a ete detruit par
 * inadvertance. Un acte destructif doit avoir un nom, demander une
 * confirmation, ne toucher qu'un compte a la fois, et laisser une trace.
 *
 * Le secret n'est PAS recuperable une fois efface : il est chiffre au repos et
 * n'existe nulle part ailleurs. La personne devra rescanner un QR code, et
 * l'ancienne entree restera dans son application d'authentification — d'ou la
 * date portee par le libelle du nouvel enrolement.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Core\Database;
use App\Core\Env;
use App\Core\LoginGuard;
use App\Core\TwoFactor;

Env::load($root);
date_default_timezone_set('UTC');

$email    = $argv[1] ?? '';
$confirme = in_array('--confirme', $argv, true);

if ($email === '' || str_starts_with($email, '--')) {
    fwrite(STDERR, "Usage : php bin/reset-2fa.php <email> [--confirme]\n");
    exit(1);
}

$pdo  = Database::get();
$stmt = $pdo->prepare(
    'SELECT user_id, user_email, user_name, user_role, user_status,
            user_totp_enabled_at, user_date_login
       FROM t_user WHERE user_email = :e LIMIT 1'
);
$stmt->execute(['e' => $email]);
$user = $stmt->fetch();

if ($user === false) {
    fwrite(STDERR, "Aucun compte pour « $email ».\n");
    exit(1);
}

$codes = TwoFactor::remainingRecoveryCodes((int) $user['user_id']);

echo str_repeat('─', 66), "\n";
printf("  Compte             %s (%s)\n", $user['user_email'], $user['user_name']);
printf("  Role               %s · %s\n", $user['user_role'], $user['user_status']);
printf("  Verification       %s\n", $user['user_totp_enabled_at'] ?? 'non configuree');
printf("  Codes de secours   %d restant(s)\n", $codes);
printf("  Derniere connexion %s\n", $user['user_date_login'] ?? 'jamais');
echo str_repeat('─', 66), "\n";

if ($user['user_totp_enabled_at'] === null && $codes === 0) {
    echo "Ce compte n'a deja aucune verification configuree. Rien a faire.\n";
    exit(0);
}

if (!$confirme) {
    echo "\nCe qui serait fait :\n";
    echo "  · le secret TOTP est efface — IRRECUPERABLE, il n'existe nulle part ailleurs\n";
    echo "  · les $codes code(s) de secours restants sont invalides\n";
    echo "  · la personne devra rescanner un QR code a sa prochaine connexion\n";
    echo "  · l'ancienne entree restera dans son application d'authentification :\n";
    echo "    elle devra l'y supprimer a la main (le nouvel enrolement porte la date\n";
    echo "    du jour dans son libelle, pour les distinguer)\n";
    echo "\nRelance avec --confirme pour executer.\n";
    exit(0);
}

$pdo->beginTransaction();

try {
    $pdo->prepare(
        'UPDATE t_user
            SET user_totp_secret = NULL, user_totp_enabled_at = NULL, user_totp_last_step = NULL
          WHERE user_id = :i'
    )->execute(['i' => $user['user_id']]);

    $pdo->prepare('DELETE FROM t_user_recovery_code WHERE recovery_id_user = :i')
        ->execute(['i' => $user['user_id']]);

    $pdo->prepare('DELETE FROM t_user_email_code WHERE emailcode_id_user = :i')
        ->execute(['i' => $user['user_id']]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Echec : ' . $e->getMessage() . "\n");
    exit(1);
}

// La trace vit dans le meme journal que les connexions : elle apparait donc
// sur l'ecran Securite du compte concerne, et se voit.
LoginGuard::record((string) $user['user_email'], (int) $user['user_id'], 'locked', null, 'bin/reset-2fa.php');

echo "\n✓ Verification remise a zero pour {$user['user_email']}.\n";
echo "  La personne configurera une nouvelle application a sa prochaine connexion.\n";
echo "  Rappelez-lui de supprimer l'ancienne entree dans son application.\n";
