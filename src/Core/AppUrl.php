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
        $uri    = $request->getUri();
        $port   = $uri->getPort();
        $scheme = self::scheme($request);
        $defaut = ($scheme === 'https' && $port === 443)
            || ($scheme === 'http' && $port === 80)
            || ($scheme === 'https' && $port === 80);

        return $scheme . '://' . $uri->getHost()
            . ($port !== null && !$defaut ? ':' . $port : '');
    }

    /**
     * Le schema REEL vu par le navigateur.
     *
     * Vigil tourne derriere le nginx de l'hote, qui relaie en clair vers le
     * conteneur : PHP voit donc toujours `http`, meme quand le visiteur est en
     * `https`. Sans cette lecture, un `APP_URL` en `https` — la valeur correcte
     * en production — declenchait un avertissement d'ecart permanent, et le
     * repli hors `APP_URL` fabriquait des liens en clair.
     *
     * `X-Forwarded-Proto` n'est cru que s'il vient d'une adresse privee ou de
     * la boucle locale : c'est un en-tete qu'un client peut forger, et le seul
     * emetteur legitime ici est le reverse proxy du meme hote.
     */
    private static function scheme(ServerRequestInterface $request): string
    {
        $scheme = $request->getUri()->getScheme();
        if ($scheme === 'https') {
            return 'https';
        }

        $transmis = strtolower(trim(explode(',', $request->getHeaderLine('X-Forwarded-Proto'))[0]));
        if ($transmis !== 'https') {
            return $scheme;
        }

        $params  = $request->getServerParams();
        $emetteur = (string) ($params['REMOTE_ADDR'] ?? '');

        return self::estRelaiDeConfiance($emetteur) ? 'https' : $scheme;
    }

    /** Boucle locale et plages privees (RFC 1918, RFC 4193), plus le reseau Docker. */
    private static function estRelaiDeConfiance(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return true;
        }

        $binaire = @inet_pton($ip);
        if ($binaire === false) {
            return false;
        }

        if (strlen($binaire) === 4) {
            $entier = unpack('N', $binaire)[1];

            return ($entier >> 24) === 10                              // 10.0.0.0/8
                || ($entier >> 20) === (172 << 4 | 1)                   // 172.16.0.0/12
                || ($entier >> 16) === (192 << 8 | 168);                // 192.168.0.0/16
        }

        return (ord($binaire[0]) & 0xFE) === 0xFC;                     // fc00::/7
    }
}
