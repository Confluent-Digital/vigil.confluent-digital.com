<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

App\Core\Env::load(__DIR__);

$db = static fn (string $name): array => [
    'adapter'   => 'mysql',
    'host'      => App\Core\Env::get('DB_HOST', '127.0.0.1'),
    'port'      => (int) App\Core\Env::get('DB_PORT', '3306'),
    'name'      => $name,
    'user'      => App\Core\Env::get('DB_USERNAME', 'vigil'),
    'pass'      => App\Core\Env::get('DB_PASSWORD', ''),
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
];

return [
    'paths' => [
        'migrations' => __DIR__ . '/database/migrations',
        'seeds'      => __DIR__ . '/database/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment'     => 'development',
        'development' => $db(App\Core\Env::get('DB_NAME', 'bd_vigil')),
        'testing'     => $db(App\Core\Env::get('DB_NAME', 'bd_vigil') . '_test'),
    ],
    'version_order' => 'creation',
];
