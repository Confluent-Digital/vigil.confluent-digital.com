<?php

declare(strict_types=1);

/**
 * Point d'entree unique des tasks cron.
 *
 *   php bin/task_runner.php <Module> <TaskClass> [methode] [args...]
 *
 * Exemple : php bin/task_runner.php Postback PostbackFlushTask send
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

App\Core\Env::load($root);
date_default_timezone_set('UTC');

if ($argc < 3) {
    fwrite(STDERR, "Usage : php bin/task_runner.php <Module> <TaskClass> [methode] [args...]\n");
    exit(1);
}

[$module, $task] = [$argv[1], $argv[2]];
$method = $argv[3] ?? 'run';
$args   = array_slice($argv, 4);

$class = sprintf('App\\Modules\\%s\\Tasks\\%s', $module, $task);

if (!class_exists($class)) {
    fwrite(STDERR, "Task introuvable : $class\n");
    exit(1);
}

$instance = new $class($root . '/logs');

if (!method_exists($instance, $method)) {
    fwrite(STDERR, "Methode introuvable : $class::$method\n");
    exit(1);
}

$start = microtime(true);

try {
    $result = $instance->$method(...$args);
    printf(
        "[%s] %s::%s -> %s (%.2fs)\n",
        gmdate('Y-m-d H:i:s'),
        $task,
        $method,
        is_scalar($result) ? (string) $result : gettype($result),
        microtime(true) - $start
    );
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, sprintf(
        "[%s] %s::%s ECHEC : %s (%s:%d)\n",
        gmdate('Y-m-d H:i:s'),
        $task,
        $method,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
    exit(1);
}
