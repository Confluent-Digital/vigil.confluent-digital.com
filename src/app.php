<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use DI\Container;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

$root = dirname(__DIR__);

Env::load($root);
Auth::start();

date_default_timezone_set('UTC');

$container = new Container();

$container->set(Twig::class, static function () use ($root): Twig {
    // Le cache Twig vit DANS le conteneur, pas sur le volume monte.
    //
    // C'est du PHP compile, regenerable a tout moment : rien qui merite d'etre
    // conserve. Le placer dans un repertoire partage avec l'hote n'apportait
    // rien et exposait a toute la classe des conflits de droits — le conteneur
    // tourne sous un UID fixe par le .env, l'hote sous un autre, et Twig
    // echoue sur « Unable to create the cache directory » sans que la cause
    // soit lisible.
    //
    // Effet de bord bienvenu : redemarrer le conteneur purge le cache. Plus
    // besoin d'un `rm -rf cache/twig/*` au deploiement, ni de s'en souvenir.
    $cache = false;

    if (Env::get('APP_ENV') === 'production') {
        $cache = rtrim((string) Env::get('TWIG_CACHE_DIR', sys_get_temp_dir() . '/vigil-twig'), '/');

        if (!is_dir($cache) && !@mkdir($cache, 0775, true) && !is_dir($cache)) {
            // Plutot que de laisser Twig echouer a la premiere page rendue,
            // on renonce au cache et on le dit. Une application lente vaut
            // mieux qu'une application morte.
            error_log('[vigil] cache Twig indisponible (' . $cache . ') : compilation a chaque requete');
            $cache = false;
        }
    }

    $twig = Twig::create($root . '/src/Views', [
        'cache'       => $cache,
        'debug'       => Env::bool('APP_DEBUG'),
        // auto_reload actif : sans lui, une modification de template n'apparait
        // jamais tant que cache/twig n'a pas ete purge a la main.
        'auto_reload' => true,
    ]);

    $env = $twig->getEnvironment();
    $env->addGlobal('app_name', Env::get('APP_NAME', 'Vigil'));
    $env->addGlobal('app_url', App\Core\AppUrl::base());
    $env->addGlobal('current_user', Auth::user());
    $env->addGlobal('csrf_token', Csrf::token());

    // Le menu ne montre le bac a sable que la ou il existe : hors dev, les
    // routes ne sont meme pas enregistrees, une entree pointerait dans le vide.
    $env->addGlobal('sandbox_enabled', App\Core\Sandbox::isEnabled());

    // Messages one-shot : lus puis effaces, sinon ils reapparaissent au
    // rechargement suivant et laissent croire que l'action s'est rejouee.
    $env->addGlobal('flash_ok', $_SESSION['flash_ok'] ?? null);
    $env->addGlobal('flash_error', $_SESSION['flash_error'] ?? null);
    unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

    // Les dates sont stockees en UTC et affichees en Europe/Paris : c'est la
    // premiere chose que regarde un client qui conteste un chiffre.
    $env->addFilter(new \Twig\TwigFilter('paris', static function (?string $utc, string $fmt = 'd/m/Y H:i'): string {
        if ($utc === null || $utc === '') {
            return '—';
        }
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Europe/Paris'))
            ->format($fmt);
    }));

    $env->addFilter(new \Twig\TwigFilter('money', static function (float|string|null $n, string $cur = 'EUR'): string {
        if ($n === null) {
            return '—';
        }
        $symbol = ['EUR' => '€', 'USD' => '$', 'GBP' => '£'][$cur] ?? $cur;
        return number_format((float) $n, 2, ',', ' ') . ' ' . $symbol;
    }));

    return $twig;
});

AppFactory::setContainer($container);
$app = AppFactory::create();

$app->addRoutingMiddleware();
$app->add(TwigMiddleware::createFromContainer($app, Twig::class));

$errorMiddleware = $app->addErrorMiddleware(Env::bool('APP_DEBUG'), true, true);

(require $root . '/src/routes.php')($app);

return $app;
