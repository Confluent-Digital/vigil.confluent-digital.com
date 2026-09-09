<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Tout POST du back-office porte un jeton. Sans cela, un site tiers pourrait
 * faire changer l'URL de destination d'une campagne au navigateur d'un
 * administrateur connecte — et detourner le trafic sans jamais toucher Vigil.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $handler->handle($request);
        }

        $body = (array) $request->getParsedBody();
        if (Csrf::valid($body['csrf'] ?? null)) {
            return $handler->handle($request);
        }

        $response = new Response();
        $response->getBody()->write('Jeton CSRF invalide ou expire. Recharge la page et recommence.');

        return $response->withStatus(419)->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }
}
