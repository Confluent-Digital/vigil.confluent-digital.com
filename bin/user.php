<?php

declare(strict_types=1);

/**
 * Gestion des comptes du back-office.
 *
 *   php bin/user.php list
 *   php bin/user.php create <email> [role]     role : admin (defaut) | manager | publisher
 *   php bin/user.php password <email>          reinitialise, affiche une fois
 *   php bin/user.php email <ancien> <nouveau>  change l'adresse
 *   php bin/user.php disable <email>           desactive sans supprimer
 *   php bin/user.php enable <email>
 *
 * Les mots de passe sont haches en bcrypt : ils ne se relisent pas, meme pour
 * un administrateur. « Retrouver » un mot de passe n'existe pas — on le
 * reinitialise, et le nouveau s'affiche une seule fois.
 *
 * Pour la verification en deux etapes, voir `bin/reset-2fa.php`.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Core\Database;
use App\Core\Env;
use App\Core\Token;

Env::load($root);
date_default_timezone_set('UTC');

$commande = $argv[1] ?? '';
$pdo      = Database::get();

/** Une adresse doit etre une VRAIE boite : le repli du second facteur y envoie. */
function verifierAdresse(string $email): void
{
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        fwrite(STDERR, "Adresse invalide : $email\n");
        exit(1);
    }
}

function trouver(PDO $pdo, string $email): array
{
    $stmt = $pdo->prepare('SELECT * FROM t_user WHERE user_email = :e LIMIT 1');
    $stmt->execute(['e' => $email]);
    $user = $stmt->fetch();

    if ($user === false) {
        fwrite(STDERR, "Aucun compte pour « $email ».\n");
        fwrite(STDERR, "Liste des comptes : php bin/user.php list\n");
        exit(1);
    }

    return $user;
}

function afficherIdentifiants(string $email, string $motDePasse, bool $aConfigurerLe2fa = true): void
{
    echo "\n", str_repeat('=', 62), "\n";
    echo "  E-mail        : $email\n";
    echo "  Mot de passe  : $motDePasse\n";
    echo str_repeat('=', 62), "\n";
    echo "Note-le : il est hache en base et ne sera plus jamais affiche.\n";
    if ($aConfigurerLe2fa) {
        echo "La verification en deux etapes se configure a la premiere connexion.\n";
    }
    echo "\n";
}

switch ($commande) {

    case 'list':
        $lignes = $pdo->query(
            'SELECT user_email, user_name, user_role, user_status,
                    user_totp_enabled_at, user_date_login
               FROM t_user ORDER BY user_email'
        )->fetchAll();

        if ($lignes === []) {
            echo "Aucun compte. Cree le premier : php bin/user.php create <email>\n";
            break;
        }

        printf("\n%-38s %-10s %-9s %-14s %s\n", 'E-MAIL', 'ROLE', 'ETAT', 'VERIFICATION', 'DERNIERE CONNEXION');
        echo str_repeat('-', 100), "\n";
        foreach ($lignes as $u) {
            printf(
                "%-38s %-10s %-9s %-14s %s\n",
                $u['user_email'],
                $u['user_role'],
                $u['user_status'],
                $u['user_totp_enabled_at'] !== null ? 'configuree' : 'a configurer',
                $u['user_date_login'] ?? 'jamais'
            );
        }
        echo "\n";
        break;

    case 'create':
        $email = $argv[2] ?? '';
        $role  = $argv[3] ?? 'admin';
        verifierAdresse($email);

        if (!in_array($role, ['admin', 'manager', 'publisher'], true)) {
            fwrite(STDERR, "Role inconnu : $role (admin, manager ou publisher)\n");
            exit(1);
        }

        $stmt = $pdo->prepare('SELECT user_id FROM t_user WHERE user_email = :e');
        $stmt->execute(['e' => $email]);
        if ($stmt->fetch() !== false) {
            fwrite(STDERR, "Ce compte existe deja. Pour changer son mot de passe :\n");
            fwrite(STDERR, "  php bin/user.php password $email\n");
            exit(1);
        }

        $motDePasse = Token::generate(16);
        $nom        = ucfirst(explode('@', $email)[0]);

        $pdo->prepare(
            'INSERT INTO t_user (user_email, user_password, user_name, user_role, user_status)
             VALUES (:e, :p, :n, :r, \'active\')'
        )->execute([
            'e' => $email,
            'p' => password_hash($motDePasse, PASSWORD_BCRYPT, ['cost' => 12]),
            'n' => $nom,
            'r' => $role,
        ]);

        echo "Compte cree ($role).\n";
        afficherIdentifiants($email, $motDePasse);
        break;

    case 'password':
        $email = $argv[2] ?? '';
        verifierAdresse($email);
        $user = trouver($pdo, $email);

        $motDePasse = Token::generate(16);
        $pdo->prepare('UPDATE t_user SET user_password = :p WHERE user_id = :i')
            ->execute([
                'p' => password_hash($motDePasse, PASSWORD_BCRYPT, ['cost' => 12]),
                'i' => $user['user_id'],
            ]);

        $dejaConfigure = $user['user_totp_enabled_at'] !== null;

        echo "Mot de passe reinitialise.\n";
        if ($dejaConfigure) {
            // Le secret TOTP n'a aucun lien avec le mot de passe : le
            // reinitialiser ne doit surtout pas laisser croire qu'il faut
            // rescanner un QR code — c'est en croyant cela qu'on efface un
            // enrolement parfaitement valide (voir bin/reset-2fa.php).
            echo "La verification en deux etapes n'est PAS touchee : garde la meme\n";
            echo "entree dans ton application d'authentification.\n";
        }
        afficherIdentifiants($email, $motDePasse, !$dejaConfigure);
        break;

    case 'email':
        $ancien  = $argv[2] ?? '';
        $nouveau = $argv[3] ?? '';
        verifierAdresse($ancien);
        verifierAdresse($nouveau);
        $user = trouver($pdo, $ancien);

        $stmt = $pdo->prepare('SELECT user_id FROM t_user WHERE user_email = :e');
        $stmt->execute(['e' => $nouveau]);
        if ($stmt->fetch() !== false) {
            fwrite(STDERR, "« $nouveau » est deja pris par un autre compte.\n");
            exit(1);
        }

        $pdo->prepare('UPDATE t_user SET user_email = :e WHERE user_id = :i')
            ->execute(['e' => $nouveau, 'i' => $user['user_id']]);

        echo "Adresse changee : $ancien -> $nouveau\n";
        echo "Le mot de passe et la verification en deux etapes sont conserves :\n";
        echo "le secret TOTP est rattache au compte, pas a l'adresse.\n";
        echo "\nSeule difference visible : le libelle dans l'application\n";
        echo "d'authentification garde l'ancienne adresse. Sans consequence — pour\n";
        echo "le mettre a jour, il faut reconfigurer (php bin/reset-2fa.php).\n";
        break;

    case 'disable':
    case 'enable':
        $email = $argv[2] ?? '';
        verifierAdresse($email);
        $user  = trouver($pdo, $email);
        $etat  = $commande === 'enable' ? 'active' : 'disabled';

        $pdo->prepare('UPDATE t_user SET user_status = :s WHERE user_id = :i')
            ->execute(['s' => $etat, 'i' => $user['user_id']]);

        echo "Compte $email : $etat\n";
        break;

    default:
        fwrite(STDERR, "Usage :\n");
        fwrite(STDERR, "  php bin/user.php list\n");
        fwrite(STDERR, "  php bin/user.php create <email> [admin|manager|publisher]\n");
        fwrite(STDERR, "  php bin/user.php password <email>\n");
        fwrite(STDERR, "  php bin/user.php email <ancien> <nouveau>\n");
        fwrite(STDERR, "  php bin/user.php disable|enable <email>\n");
        exit(1);
}
