<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Compte d'acces initial. Le mot de passe est genere aleatoirement et affiche
 * une seule fois : il n'y a pas de mot de passe par defaut a oublier de changer.
 */
final class AdminUserSeeder extends AbstractSeed
{
    public function run(): void
    {
        $email = App\Core\Env::get('ADMIN_EMAIL', 'admin@confluent-digital.com');

        // Requete preparee via PDO plutot que l'adaptateur Phinx : son API de
        // citation varie d'une version a l'autre, et on ne veut pas d'un seed
        // qui casse au prochain composer update.
        $pdo  = App\Core\Database::get();
        $stmt = $pdo->prepare('SELECT user_id FROM t_user WHERE user_email = :email');
        $stmt->execute(['email' => $email]);

        if ($stmt->fetch() !== false) {
            echo "Le compte $email existe deja — inchange.\n";
            return;
        }

        $password = App\Core\Token::generate(16);

        $this->table('t_user')->insert([
            'user_email'    => $email,
            'user_password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            'user_name'     => 'Administrateur',
            'user_role'     => 'admin',
            'user_status'   => 'active',
        ])->saveData();

        echo str_repeat('=', 62) . "\n";
        echo "Compte administrateur cree.\n";
        echo "  E-mail        : $email\n";
        echo "  Mot de passe  : $password\n";
        echo "Note-le : il n'est affiche qu'une fois.\n";
        echo str_repeat('=', 62) . "\n";
    }
}
