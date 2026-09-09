<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Le bac a sable : un money site et une plateforme externe simules, pour voir
 * la boucle complete tourner sans toucher a un tiers.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * IL N'EXISTE PAS EN PRODUCTION.
 *
 * Une route capable de declencher un postback est un outil de forge de
 * conversions avec une interface conviviale : qui la trouve credite des
 * conversions qui partiront chez les partenaires et se factureront.
 *
 * On ne la protege donc pas, on ne l'enregistre pas du tout. Une route qui
 * n'existe pas ne se contourne pas — il n'y a ni verification d'autorisation a
 * oublier, ni bogue de session a exploiter.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class Sandbox
{
    /** Environnements ou le bac a sable est monte. */
    private const ENVIRONNEMENTS = ['dev', 'development', 'local', 'test'];

    /**
     * Prefixe de tout ce que le bac a sable cree. C'est la SEULE marque qui
     * distingue une donnee d'essai d'une donnee reelle : la suppression s'y
     * fie entierement, donc rien ne doit etre cree sans lui.
     */
    public const PREFIX = '[BAC A SABLE]';

    /** @deprecated conserve pour la compatibilite des anciens jeux. */
    public const CLIENT = self::PREFIX;

    public static function isEnabled(): bool
    {
        return in_array(strtolower((string) Env::get('APP_ENV', '')), self::ENVIRONNEMENTS, true);
    }

    /**
     * Adresse INTERNE, pour ce que le serveur appelle lui-meme : le faux money
     * site qui invoque `/pb`, et le worker qui livre les relais. En HTTP, pas
     * par un appel de fonction — sinon on ne traverserait ni nginx, ni PHP-FPM,
     * ni les en-tetes, c'est-a-dire pas grand-chose.
     *
     * ⚠ NE JAMAIS l'utiliser pour une URL que le NAVIGATEUR devra suivre :
     * `vigil_nginx` est un nom du reseau Docker, il ne resout pas hors des
     * conteneurs. Une redirection vers cette adresse mene le visiteur nulle
     * part. Pour cela, c'est `publicBaseUrl()`.
     */
    public static function internalBaseUrl(): string
    {
        return rtrim((string) Env::get('TEST_BASE_URL', 'http://vigil_nginx'), '/');
    }

    /**
     * Adresse PUBLIQUE, pour ce que le navigateur doit atteindre : la
     * destination d'une campagne, suivie par le visiteur apres la redirection.
     *
     * Symetrie exacte du probleme : `vigil_nginx` est joignable depuis les
     * conteneurs et pas du navigateur ; `APP_URL` l'inverse. Confondre les deux
     * casse silencieusement l'un des deux cotes.
     */
    public static function publicBaseUrl(): string
    {
        $publique = rtrim((string) Env::get('APP_URL', ''), '/');

        return $publique !== '' ? $publique : self::internalBaseUrl();
    }

    /**
     * Appel HTTP interne. Refuse toute cible hors du serveur local : le bac a
     * sable ne doit jamais servir de tremplin vers l'exterieur.
     *
     * @return array{code: int, body: string, error: string|null, url: string}
     */
    public static function call(string $path, string $method = 'GET', string $body = ''): array
    {
        $url = self::internalBaseUrl() . '/' . ltrim($path, '/');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'Vigil-Sandbox/1.0',
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $reponse = curl_exec($ch);
        $code    = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $erreur  = curl_errno($ch) !== 0 ? curl_error($ch) : null;
        curl_close($ch);

        return [
            'code'  => $code,
            'body'  => is_string($reponse) ? substr($reponse, 0, 2000) : '',
            'error' => $erreur,
            'url'   => $url,
        ];
    }

    /**
     * Journal de la boucle, dans un fichier — pas en session.
     *
     * Le worker de relais appelle la fausse plateforme par HTTP interne, sans
     * cookie : sa trace atterrirait dans une session vide, et l'etape la plus
     * interessante de la boucle serait justement celle qu'on ne verrait pas.
     * Rien de tout cela ne merite la base pour autant.
     */
    public static function log(string $etape, string $detail, array $donnees = []): void
    {
        $entrees = self::journal();

        array_unshift($entrees, [
            'at'      => microtime(true),
            'etape'   => $etape,
            'detail'  => $detail,
            'donnees' => $donnees,
        ]);

        self::ecrire(array_slice($entrees, 0, 60));
    }

    /** @return array<int, array<string, mixed>> */
    public static function journal(): array
    {
        $fichier = self::fichier();
        if (!is_readable($fichier)) {
            return [];
        }

        $contenu = json_decode((string) file_get_contents($fichier), true);

        return is_array($contenu) ? $contenu : [];
    }

    public static function clearJournal(): void
    {
        self::ecrire([]);
    }

    private static function fichier(): string
    {
        return dirname(__DIR__, 2) . '/logs/sandbox-journal.json';
    }

    /** @param array<int, array<string, mixed>> $entrees */
    private static function ecrire(array $entrees): void
    {
        $fichier = self::fichier();
        if (!is_dir(dirname($fichier))) {
            @mkdir(dirname($fichier), 0775, true);
        }

        // LOCK_EX : le worker et le navigateur peuvent ecrire en meme temps.
        @file_put_contents(
            $fichier,
            (string) json_encode($entrees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
