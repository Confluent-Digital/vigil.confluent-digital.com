<?php

/**
 * Chemin chaud — le clic.
 *
 *   GET /c/<token>?s1=..&cid=..  ->  302 vers le money site
 *
 * AUCUN framework ici : pas de conteneur DI, pas de session, pas de Twig, pas
 * d'autoload Composer complet. Le boot de Slim coute ~10 ms pour un travail
 * utile de l'ordre de 0,5 ms — c'est la seule optimisation qui compte vraiment
 * sur cette route. Voir .claude/rules/tracking.md avant toute modification.
 */

declare(strict_types=1);

use App\Core\CampaignCache;
use App\Core\ClickContext;
use App\Core\Database;
use App\Core\Env;
use App\Core\LinkMiss;
use App\Core\MacroEngine;
use App\Core\Prefill;
use App\Core\Ulid;

$root = dirname(__DIR__);

require $root . '/src/Core/Env.php';
require $root . '/src/Core/Database.php';
require $root . '/src/Core/Ulid.php';
require $root . '/src/Core/MacroEngine.php';
require $root . '/src/Core/ClickContext.php';
require $root . '/src/Core/LinkMiss.php';
require $root . '/src/Core/Prefill.php';
require $root . '/src/Core/CampaignCache.php';

Env::load($root);

/**
 * Rend la page « lien expire » et journalise le 404.
 *
 * Le visiteur part d'abord : la journalisation se fait apres
 * `fastcgi_finish_request()`, comme l'ecriture d'un clic. Un 404 ne doit pas
 * couter plus d'attente qu'une redirection.
 */
function rejeter(string $token, ?array $diagnostic = null): void
{
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Referrer-Policy: no-referrer');

    $page = LinkMiss::page();
    header('Content-Length: ' . strlen($page));
    echo $page;

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    // Le diagnostic ET la journalisation se font ICI, apres le depart du
    // visiteur. Interroger la base avant de repondre ferait payer au visiteur
    // le prix de notre outillage : un 404 doit partir aussi vite qu'une
    // redirection.
    try {
        $pdo = Database::get();

        LinkMiss::record(
            $pdo,
            $token,
            $diagnostic ?? LinkMiss::diagnose($pdo, $token),
            ClickContext::clientIp($_SERVER),
            (string) ($_SERVER['HTTP_REFERER'] ?? '')
        );
    } catch (Throwable $e) {
        error_log('[vigil/c] journalisation du 404 impossible : ' . $e->getMessage());
    }
}

// ── 1. Token ────────────────────────────────────────────────────────────────
$path  = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
$token = '';
if (preg_match('#/c/([A-Za-z0-9_-]{1,24})#', $path, $m) === 1) {
    $token = $m[1];
}

if ($token === '') {
    // Toutes les saisies aberrantes sont agregees sous une ligne unique par
    // jour : sans cela, un scanner ferait grossir la table sans limite.
    rejeter(LinkMiss::TOKEN_MALFORME, ['raison' => 'token_malforme', 'campagne' => null, 'publisher' => null]);
    return;
}

// ── 2. Campagne ─────────────────────────────────────────────────────────────
try {
    $link = CampaignCache::get($token);
} catch (Throwable $e) {
    // Base injoignable : on ne peut pas rediriger, il n'y a pas de destination
    // a servir. On le dit clairement plutot que de renvoyer une page blanche.
    error_log('[vigil/c] resolution impossible : ' . $e->getMessage());
    http_response_code(503);
    header('Retry-After: 5');
    return;
}

if ($link === null) {
    // Cinq causes possibles, que CampaignCache ne distingue pas — c'est normal,
    // il est sur le chemin chaud. Ici on est deja hors du chemin nominal : une
    // requete de diagnostic n'y coute rien, et c'est elle qui rend le probleme
    // reparable. « Campagne en pause » se corrige en un clic ; « jeton
    // inconnu » non.
    rejeter($token);
    return;
}

// ── 3. Contexte ─────────────────────────────────────────────────────────────
$query      = $_GET;
$now        = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$clickId    = Ulid::generate();
$externalId = ClickContext::externalId($query);
$subs       = ClickContext::subs($query);
$ip         = ClickContext::clientIp($_SERVER);
$userAgent  = ClickContext::truncate((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 500);
$referer    = ClickContext::truncate((string) ($_SERVER['HTTP_REFERER'] ?? ''), 500);

// Pre-remplissage du kit mailing : ces valeurs traversent vers le money site
// et ne sont JAMAIS ecrites. Voir src/Core/Prefill.php.
$prefill = Prefill::extract($query);

$destination = MacroEngine::render((string) $link['campaign_dest_url'], Prefill::macroContext($prefill) + [
    'prefill'          => Prefill::queryFragment($prefill),
    'clickid'          => $clickId,
    'external_clickid' => $externalId,
    'campaign_id'      => $link['campaign_id'],
    'campaign_name'    => $link['campaign_name'],
    'publisher_id'     => $link['publisher_id'],
    'publisher_token'  => $link['publisher_token'],
    'sub1'             => $subs[1],
    'sub2'             => $subs[2],
    'sub3'             => $subs[3],
    'sub4'             => $subs[4],
    'sub5'             => $subs[5],
    'timestamp'        => $now->getTimestamp(),
    'datetime'         => $now->format('Y-m-d H:i:s'),
    'ip'               => $ip,
    'ua'               => $userAgent,
]);

// ── 4. Redirection ──────────────────────────────────────────────────────────
// 302 et JAMAIS 301 : un 301 est mis en cache par le navigateur, les clics
// suivants du meme utilisateur ne repassent plus par Vigil et ne sont jamais
// comptes. La panne est silencieuse et ne se voit que des semaines plus tard,
// dans l'ecart de reporting.
header('Location: ' . $destination, true, 302);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
// no-referrer et non `no-referrer-when-downgrade` : notre propre URL porte les
// valeurs de pre-remplissage. Sans cela, elles partiraient au money site dans
// l'en-tete `Referer`, puis dans ses journaux d'acces — alors meme qu'on prend
// soin de ne pas les ecrire chez nous.
header('Referrer-Policy: no-referrer');
header('Content-Length: 0');

// L'utilisateur est parti : tout ce qui suit se fait hors de son attente.
// Une base saturee doit couter des clics non traces, jamais des visiteurs
// bloques sur une page blanche.
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// ── 5. Enregistrement ───────────────────────────────────────────────────────
$isBot    = ClickContext::isBot($userAgent);
$isUnique = true;

// L'unicite passe par un SETNX Redis, pas par une requete : ce serait une
// lecture de plus par clic. Redis indisponible -> on considere le clic unique
// et on continue. Le redirect ne doit jamais dependre d'une dependance
// secondaire, et l'enregistrement non plus.
if ($ip !== null && Env::bool('CLICK_UNIQUE_CHECK', true) && class_exists('Redis')) {
    try {
        $redis = new Redis();
        $redis->connect(Env::get('REDIS_HOST', '127.0.0.1'), (int) Env::get('REDIS_PORT', '6379'), 0.2);
        $key = 'u:' . $link['campaign_id'] . ':' . sha1($ip . '|' . $userAgent);
        $isUnique = (bool) $redis->set($key, '1', ['nx', 'ex' => 86400]);
        $redis->close();
    } catch (Throwable $e) {
        $isUnique = true;
    }
}

// Tout le reste de la query string part en JSON : on capte les parametres des
// partenaires sans ajouter une colonne — et donc un cout d'ecriture — a chaque
// nouveau branchement.
//
// `Prefill::stripFrom()` en retire d'abord les douze champs du kit mailing.
// Sans cette ligne, `click_raw_query` deviendrait un fichier de prospects :
// nom, adresse electronique, telephone et date de naissance, conserves sans
// duree ni base legale. C'est l'invariant a ne pas defaire.
$known = ['s1','s2','s3','s4','s5','sub1','sub2','sub3','sub4','sub5',
          'cid','clickid','click_id','subid','sub_id','ext_id','transaction_id'];
$extra = array_diff_key(Prefill::stripFrom($query), array_flip($known));

try {
    $sql = <<<'SQL'
        INSERT INTO t_click
            (click_id, click_date, click_id_campaign, click_id_publisher,
             click_ip, click_user_agent, click_referer, click_external_id,
             click_sub1, click_sub2, click_sub3, click_sub4, click_sub5,
             click_raw_query, click_is_unique, click_is_bot, click_has_prefill)
        VALUES
            (:id, :date, :campaign, :publisher,
             INET6_ATON(:ip), :ua, :referer, :external,
             :s1, :s2, :s3, :s4, :s5,
             :raw, :unique, :bot, :prefill)
    SQL;

    $stmt = Database::get()->prepare($sql);
    $stmt->execute([
        'id'        => Ulid::toBinary($clickId),
        'date'      => $now->format('Y-m-d H:i:s.v'),
        'campaign'  => $link['campaign_id'],
        'publisher' => $link['publisher_id'],
        'ip'        => $ip,
        'ua'        => $userAgent !== '' ? $userAgent : null,
        'referer'   => $referer !== '' ? $referer : null,
        'external'  => $externalId,
        's1'        => $subs[1],
        's2'        => $subs[2],
        's3'        => $subs[3],
        's4'        => $subs[4],
        's5'        => $subs[5],
        'raw'       => $extra !== [] ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null,
        'unique'    => $isUnique ? 1 : 0,
        'bot'       => $isBot ? 1 : 0,
        // Booleen, pas les valeurs : permet de repondre a « pourquoi le
        // formulaire n'est-il pas pre-rempli ? » sans conserver la moindre
        // donnee personnelle.
        'prefill'   => $prefill !== [] ? 1 : 0,
    ]);
} catch (Throwable $e) {
    // Le visiteur est deja parti : on ne peut plus rien lui dire. Reste a ne
    // pas perdre l'information de la panne.
    error_log('[vigil/c] insert clic ' . $clickId . ' : ' . $e->getMessage());
}
