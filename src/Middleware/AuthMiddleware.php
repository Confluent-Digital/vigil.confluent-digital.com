<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class AuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (Auth::check()) {
            return $handler->handle($request);
        }

        $response = new Response();

        return $response
            ->withHeader('Location', '/login')
            ->withStatus(302);
    }
}
