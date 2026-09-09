<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

App\Core\Env::load(dirname(__DIR__));

// La suite tourne EXCLUSIVEMENT sur la base de test : elle tronque des tables.
// On surcharge avant que Database::get() n'ouvre la moindre connexion.
$_ENV['DB_NAME'] = App\Core\Env::get('DB_NAME', 'bd_vigil') . '_test';

date_default_timezone_set('UTC');
