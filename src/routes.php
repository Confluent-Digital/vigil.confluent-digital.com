<?php

declare(strict_types=1);

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Modules\Account\Controllers\SecurityController;
use App\Modules\Auth\Controllers\AuthController;
use App\Modules\Campaigns\Controllers\AccessController;
use App\Modules\Campaigns\Controllers\CampaignsController;
use App\Modules\Campaigns\Controllers\PostbacksController;
use App\Modules\Clients\Controllers\ClientsController;
use App\Modules\Dashboard\Controllers\DashboardController;
use App\Modules\Publishers\Controllers\PixelController;
use App\Modules\Publishers\Controllers\PublishersController;
use App\Modules\Sandbox\Controllers\SandboxController;
use App\Modules\Traffic\Controllers\TrafficController;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {

    // Aucune closure `static` ici : Slim lie handlers ET callbacks de groupe
    // au conteneur (CallableResolver::bindToContainer), et `Closure::bindTo`
    // rend null sur une closure statique — d'ou un TypeError au boot.
    $app->get('/', fn (Request $r, Response $s): Response
        => $s->withHeader('Location', '/app')->withStatus(302));

    // Sonde de disponibilite : ne touche pas la base, pour rester verte meme
    // quand c'est la base qui est en cause — sinon on ne sait plus distinguer
    // « PHP est mort » de « MariaDB est mort ».
    $app->get('/health', function (Request $r, Response $s): Response {
        $s->getBody()->write('ok');
        return $s->withHeader('Content-Type', 'text/plain');
    });

    // Connexion en deux temps. Ces routes sont volontairement HORS du groupe
    // /app : AuthMiddleware exige une session complete, or on est ici en train
    // de la construire. Le CSRF, lui, s'applique a chaque POST.
    $app->get('/login', [AuthController::class, 'loginForm']);
    $app->post('/login', [AuthController::class, 'login'])->add(new CsrfMiddleware());
    $app->get('/login/enroll', [AuthController::class, 'enrollForm']);
    $app->post('/login/enroll', [AuthController::class, 'enroll'])->add(new CsrfMiddleware());
    $app->get('/login/verify', [AuthController::class, 'verifyForm']);
    $app->post('/login/verify', [AuthController::class, 'verify'])->add(new CsrfMiddleware());
    $app->post('/login/email-code', [AuthController::class, 'sendEmailCode'])->add(new CsrfMiddleware());
    $app->get('/logout', [AuthController::class, 'logout']);

    $app->group('/app', function (RouteCollectorProxy $g): void {

        $g->get('', [DashboardController::class, 'index']);
        $g->get('/', [DashboardController::class, 'index']);

        $g->get('/clients', [ClientsController::class, 'index']);
        $g->get('/clients/add', [ClientsController::class, 'form']);
        $g->post('/clients/add', [ClientsController::class, 'save']);
        $g->get('/clients/{id:[0-9]+}/edit', [ClientsController::class, 'form']);
        $g->post('/clients/{id:[0-9]+}/edit', [ClientsController::class, 'save']);
        $g->post('/clients/{id:[0-9]+}/delete', [ClientsController::class, 'delete']);

        $g->get('/publishers', [PublishersController::class, 'index']);
        $g->get('/publishers/add', [PublishersController::class, 'form']);
        $g->post('/publishers/add', [PublishersController::class, 'save']);
        $g->get('/publishers/{id:[0-9]+}/edit', [PublishersController::class, 'form']);
        $g->post('/publishers/{id:[0-9]+}/edit', [PublishersController::class, 'save']);
        $g->post('/publishers/{id:[0-9]+}/delete', [PublishersController::class, 'delete']);
        // Le pixel de conversion du publisher : une destination de relai dont
        // la portee est le publisher, toutes campagnes confondues.
        $g->post('/publishers/{id:[0-9]+}/pixel', [PixelController::class, 'save']);
        $g->post('/publishers/{id:[0-9]+}/pixel/{pixel:[0-9]+}/toggle', [PixelController::class, 'toggle']);
        $g->post('/publishers/{id:[0-9]+}/pixel/{pixel:[0-9]+}/delete', [PixelController::class, 'delete']);

        // `/campaigns/add` avant `/campaigns/{id}` : dans l'ordre inverse,
        // « add » serait capture comme un identifiant.
        $g->get('/campaigns', [CampaignsController::class, 'index']);
        $g->get('/campaigns/add', [CampaignsController::class, 'form']);
        $g->post('/campaigns/add', [CampaignsController::class, 'save']);
        $g->post('/campaigns/postback-preview', [PostbacksController::class, 'preview']);
        $g->get('/campaigns/{id:[0-9]+}', [CampaignsController::class, 'detail']);
        $g->get('/campaigns/{id:[0-9]+}/edit', [CampaignsController::class, 'form']);
        $g->post('/campaigns/{id:[0-9]+}/edit', [CampaignsController::class, 'save']);
        $g->post('/campaigns/{id:[0-9]+}/access', [AccessController::class, 'add']);
        $g->post('/campaigns/{id:[0-9]+}/access/{access:[0-9]+}/toggle', [AccessController::class, 'toggle']);
        $g->post('/campaigns/{id:[0-9]+}/access/{access:[0-9]+}/regenerate', [AccessController::class, 'regenerate']);
        $g->post('/campaigns/{id:[0-9]+}/postback', [PostbacksController::class, 'save']);
        $g->post('/campaigns/{id:[0-9]+}/postback/{postback:[0-9]+}/toggle', [PostbacksController::class, 'toggle']);

        $g->get('/security', [SecurityController::class, 'index']);
        $g->post('/security/regenerate', [SecurityController::class, 'regenerate']);
        $g->get('/recovery-codes', [SecurityController::class, 'recoveryCodes']);

        $g->get('/clicks', [TrafficController::class, 'clicks']);
        $g->get('/conversions', [TrafficController::class, 'conversions']);
        $g->post('/conversions/{id:[0-9]+}/replay', [TrafficController::class, 'replay']);
        $g->get('/queue', [TrafficController::class, 'queue']);
        $g->get('/dead-links', [TrafficController::class, 'deadLinks']);
    })->add(new CsrfMiddleware())->add(new AuthMiddleware());

    // ── Bac a sable ─────────────────────────────────────────────────────────
    //
    // Ces routes NE SONT PAS ENREGISTREES en production. Une route capable de
    // declencher un postback est un outil de forge de conversions : on ne la
    // protege pas par une autorisation qu'on pourrait oublier de poser, on ne
    // la monte pas du tout. Cf. App\Core\Sandbox.
    if (\App\Core\Sandbox::isEnabled()) {
        $app->group('/sandbox', function (RouteCollectorProxy $g): void {
            $g->get('',  [SandboxController::class, 'index']);
            $g->get('/', [SandboxController::class, 'index']);
            $g->post('/seed',    [SandboxController::class, 'seed']);
            $g->post('/traffic', [SandboxController::class, 'traffic']);
            $g->post('/reset',   [SandboxController::class, 'reset']);
            $g->post('/flush',   [SandboxController::class, 'flush']);
            $g->post('/journal', [SandboxController::class, 'clearJournal']);

            // Le faux money site : c'est le navigateur du testeur qui l'ouvre,
            // il reste donc derriere l'authentification.
            // Le depart du parcours : la plateforme Confluent Digital simulee.
            $g->get('/plateforme',          [SandboxController::class, 'platformOut']);
            $g->get('/money-site',          [SandboxController::class, 'moneySite']);
            $g->post('/money-site/convert', [SandboxController::class, 'convert']);
        })->add(new AuthMiddleware());

        // La fausse plateforme externe est appelee par le WORKER, en HTTP
        // interne et sans cookie : derriere AuthMiddleware elle rendrait un 302
        // vers la connexion, que le worker compterait — a juste titre — comme
        // un echec. Elle vit donc hors du groupe authentifie. Ce n'est pas une
        // ouverture : la route entiere n'existe pas en production, et elle ne
        // fait qu'ecrire une ligne dans un journal de developpement.
        $app->any('/sandbox/platform', [SandboxController::class, 'platform']);
    }
};
