<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Connexion PDO unique. Utilisable depuis le chemin chaud comme depuis Slim :
 * c'est la seule dependance que `c.php` et `pb.php` s'autorisent.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = Env::get('DB_HOST', '127.0.0.1');
        $port = Env::get('DB_PORT', '3306');
        $name = Env::get('DB_NAME', 'bd_vigil');
        $char = Env::get('DB_CHARSET', 'utf8mb4');

        self::$pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $name, $char),
            Env::get('DB_USERNAME', 'vigil'),
            Env::get('DB_PASSWORD', ''),
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Requetes reellement preparees cote serveur : c'est ce qui
                // rend l'injection SQL impossible, pas l'echappement.
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]
        );

        // Tout Vigil vit en UTC. La session MySQL doit suivre, sinon NOW() et
        // les colonnes datetime divergent de ce qu'ecrit PHP.
        self::$pdo->exec("SET time_zone = '+00:00'");

        return self::$pdo;
    }

    /** Reinitialise la connexion — utile entre deux tests. */
    public static function reset(): void
    {
        self::$pdo = null;
    }
}
