<?php

declare(strict_types=1);

namespace App\Core;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Base publique de l'application — celle qui prefixe les liens de tracking.
 *
 * `APP_URL` fait autorite quand il est renseigne, et c'est voulu : un lien de
 * tracking est un objet PUBLIC, qu'un publisher colle dans ses e-mails. Le
 * deduire de la requete courante ferait qu'un administrateur naviguant sur
 * `127.0.0.1:388` copierait un lien vers `127.0.0.1` avant de le transmettre.
 * Un lien doit aussi pouvoir etre construit hors requete, depuis une task.
 *
 * Le repli sur la requete ne sert donc qu'au cas ou `APP_URL` est absent : il
 * vaut mieux un lien qui fonctionne pour celui qui regarde qu'un lien vide.
 */
final class AppUrl
{
    public static function base(?ServerRequestInterface $request = null): string
    {
        $configuree = trim((string) Env::get('APP_URL', ''));
        if ($configuree !== '') {
            return rtrim($configuree, '/');
        }

        if ($request === null) {
            return '';
        }

        $uri  = $request->getUri();
        $port = $uri->getPort();
        $defaut = ($uri->getScheme() === 'https' && $port === 443)
            || ($uri->getScheme() === 'http' && $port === 80);

        return $uri->getScheme() . '://' . $uri->getHost()
            . ($port !== null && !$defaut ? ':' . $port : '');
    }

    /**
     * L'adresse consultee correspond-elle a `APP_URL` ?
     *
     * Sert a prevenir dans le back-office : un lien de tracking affiche en
     * `https://vigil...` alors qu'on navigue en `http://dev.vigil...` n'est pas
     * cliquable, et l'ecart n'est pas evident a l'oeil.
     */
    public static function matchesRequest(ServerRequestInterface $request): bool
    {
        $configuree = trim((string) Env::get('APP_URL', ''));
        if ($configuree === '') {
            return true;
        }

        return rtrim($configuree, '/') === self::fromRequest($request);
    }

    public static function fromRequest(ServerRequestInterface $request): string
    {
        $uri  = $request->getUri();
        $port = $uri->getPort();
        $defaut = ($uri->getScheme() === 'https' && $port === 443)
            || ($uri->getScheme() === 'http' && $port === 80);

        return $uri->getScheme() . '://' . $uri->getHost()
            . ($port !== null && !$defaut ? ':' . $port : '');
    }
}
