<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Acces au .env. Charge une seule fois, y compris depuis le chemin chaud qui
 * n'instancie pas le conteneur de l'application.
 */
final class Env
{
    private static bool $loaded = false;

    public static function load(string $rootDir): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        $file = $rootDir . '/.env';
        if (!is_readable($file)) {
            return;
        }

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);
            // Les valeurs du .env ne sont pas censees etre quotees, mais on
            // tolere les guillemets plutot que de les propager en base.
            if (strlen($value) > 1 && ($value[0] === '"' || $value[0] === "'")
                && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            $_ENV[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return (string) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }
}
