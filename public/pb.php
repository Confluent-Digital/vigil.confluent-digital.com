<?php

/**
 * Chemin chaud — le postback entrant.
 *
 *   GET|POST /pb?clickid=<ulid>&txid=<id>&payout=<n>&status=<s>&s=<secret>
 *
 * C'est la SEULE URL que le client configure sur son money site : tout le
 * demultiplexage vers les plateformes externes se fait ici.
 *
 * Aucun framework, et aucun appel HTTP sortant. Voir .claude/rules/postback.md.
 */

declare(strict_types=1);

use App\Core\ClickContext;
use App\Core\Database;
use App\Core\Env;
use App\Core\PostbackAuth;
use App\Core\PostbackMiss;
use App\Core\PostbackRouter;
use App\Core\Ulid;

$root = dirname(__DIR__);

require $root . '/src/Core/Env.php';
require $root . '/src/Core/Database.php';
require $root . '/src/Core/Ulid.php';
require $root . '/src/Core/MacroEngine.php';
require $root . '/src/Core/ClickContext.php';
require $root . '/src/Core/PostbackAuth.php';
require $root . '/src/Core/PostbackMiss.php';
require $root . '/src/Core/PostbackRouter.php';

Env::load($root);

$input = array_merge($_GET, $_POST);
$ip    = ClickContext::clientIp($_SERVER);

/**
 * Repond puis rend la main. Le money site recoit toujours 200 : un 404 sur un
 * clickid inconnu declenche chez lui des files de retry qui polluent ses logs
 * pour une conversion qui, de toute facon, ne sera jamais rattachable.
 */
$respond = static function (
    string $body,
    string $logLine = '',
    ?string $raison = null,
    ?int $campaignId = null,
    string $detail = ''
) use ($input, $ip): void {
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('Content-Length: ' . strlen($body));
    echo $body;

    if ($logLine !== '') {
        error_log('[vigil/pb] ' . $logLine);
    }

    if ($raison === null) {
        return;
    }

    // Le refus est journalise APRES le depart du money site : il ne doit pas
    // attendre notre outillage. Sans cette trace, brancher une campagne se fait
    // a l'aveugle — il voit « HTTP 200 » et croit que ca marche.
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    PostbackMiss::record($raison, $campaignId, $input, $ip, $detail);
};

$clickId = trim((string) ($input['clickid'] ?? $input['click_id'] ?? $input['cid'] ?? ''));
if ($clickId === '' || !Ulid::isValid($clickId)) {
    $respond('OK', 'clickid absent ou malforme : ' . substr($clickId, 0, 40),
        $clickId === '' ? 'clickid_absent' : 'clickid_malforme');
    return;
}

try {
    $pdo = Database::get();

    // ── 1. Retrouver le clic ────────────────────────────────────────────────
    // t_click est partitionnee par mois et sa PK est (click_id, click_date) :
    // chercher sur le seul click_id scannerait toutes les partitions. Les 48
    // premiers bits de l'ULID portent l'horodatage, on borne donc la date.
    // La fenetre de +/- 1 jour absorbe les decalages d'horloge et les
    // partitions limitrophes.
    $ms   = Ulid::timestamp($clickId) ?? 0;
    $when = (new DateTimeImmutable('@' . intdiv($ms, 1000)))->setTimezone(new DateTimeZone('UTC'));

    $stmt = $pdo->prepare(
        'SELECT c.click_id_campaign, c.click_id_publisher, c.click_external_id, c.click_date,
                c.click_sub1, c.click_sub2, c.click_sub3, c.click_sub4, c.click_sub5,
                INET6_NTOA(c.click_ip) AS click_ip_text, c.click_country, c.click_user_agent,
                p.publisher_token
           FROM t_click c
      LEFT JOIN t_publisher p ON p.publisher_id = c.click_id_publisher
          WHERE c.click_id = :id
            AND c.click_date BETWEEN :from AND :to
          LIMIT 1'
    );
    $stmt->execute([
        'id'   => Ulid::toBinary($clickId),
        'from' => $when->modify('-1 day')->format('Y-m-d H:i:s'),
        'to'   => $when->modify('+1 day')->format('Y-m-d H:i:s'),
    ]);
    $click = $stmt->fetch();

    if ($click === false) {
        $respond('OK', 'clickid inconnu : ' . $clickId, 'clickid_inconnu');
        return;
    }

    // ── 2. Campagne et authentification ─────────────────────────────────────
    // LEFT JOIN, jamais INNER : `campaign_id_client` est nullable, et une
    // campagne orpheline ferait disparaitre la ligne entiere. Le postback
    // tomberait alors en « campagne introuvable » — un `200` rendu au money
    // site, aucune conversion creee, et rien pour le signaler ailleurs que
    // dans l'ecart de reporting. Une campagne sans client doit continuer a
    // encaisser ses conversions ; elle perd seulement le secret partage.
    $stmt = $pdo->prepare(
        'SELECT c.campaign_id, c.campaign_name, c.campaign_payout, c.campaign_currency,
                c.campaign_postback_secret, c.campaign_postback_ips, c.campaign_postback_response,
                cl.client_postback_secret
           FROM t_campaign c
      LEFT JOIN t_client cl ON cl.client_id = c.campaign_id_client
          WHERE c.campaign_id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $click['click_id_campaign']]);
    $campaign = $stmt->fetch();

    if ($campaign === false) {
        $respond('OK', 'campagne introuvable pour le clic ' . $clickId, 'campagne_introuvable',
            (int) $click['click_id_campaign']);
        return;
    }

    $reply  = (string) ($campaign['campaign_postback_response'] ?: 'OK');
    $secret = isset($input['s']) ? (string) $input['s'] : (isset($input['secret']) ? (string) $input['secret'] : null);

    $auth = PostbackAuth::check($campaign, $secret, $ip);
    if ($auth !== true) {
        // Refus silencieux cote money site, trace cote Vigil : dire « secret
        // invalide » a l'appelant l'aiderait a le deviner.
        $respond(
            $reply,
            'REFUSE (' . $auth . ') clic=' . $clickId,
            str_starts_with($auth, 'ip') ? 'ip_refusee' : 'secret_invalide',
            (int) $campaign['campaign_id'],
            $auth
        );
        return;
    }

    // ── 3. Idempotence ──────────────────────────────────────────────────────
    // A defaut de txid, on retombe sur le clic : une conversion par clic. C'est
    // degrade — cela interdit deux ventes sur un meme clic — et il faut
    // reclamer un txid au client.
    $txid = trim((string) ($input['txid'] ?? $input['transaction_id'] ?? $input['order_id'] ?? ''));
    if ($txid === '') {
        $txid = 'click:' . $clickId;
    }
    $txid = ClickContext::truncate($txid, 128);

    $status = strtolower(trim((string) ($input['status'] ?? 'approved')));
    if (!in_array($status, ['pending', 'approved', 'rejected', 'chargeback'], true)) {
        $status = 'approved';
    }

    // `amount` est le nom employe par les money sites branches sur la plateforme
    // externe. On ne peut pas leur imposer le notre : le postback est configure
    // chez eux, souvent dans une interface sans champ libre.
    $payout = (float) $campaign['campaign_payout'];
    foreach (['payout', 'amount'] as $champ) {
        if (isset($input[$champ]) && is_numeric($input[$champ])) {
            $payout = (float) $input[$champ];
            break;
        }
    }
    $revenue = isset($input['revenue']) && is_numeric($input['revenue'])
        ? (float) $input['revenue']
        : 0.0;

    // Statut actuel, lu AVANT l'ecriture : c'est lui qui decide si un relai
    // doit repartir. Sans cette lecture, un money site qui retente cinq fois
    // son postback empilerait cinq relais — la conversion resterait unique,
    // mais le partenaire en recevrait cinq. L'idempotence de la ligne ne suffit
    // pas, il faut celle de l'effet.
    $previous = $pdo->prepare(
        'SELECT conversion_status FROM t_conversion
          WHERE conversion_id_campaign = :campaign AND conversion_external_txid = :txid
          LIMIT 1'
    );
    $previous->execute(['campaign' => $campaign['campaign_id'], 'txid' => $txid]);
    $previousStatus = $previous->fetchColumn();

    // ON DUPLICATE KEY UPDATE et jamais un INSERT simple : un money site qui
    // retente son postback creerait sinon une seconde conversion, relayee une
    // seconde fois chez le partenaire.
    $sql = <<<'SQL'
        INSERT INTO t_conversion
            (conversion_id_click, conversion_click_date, conversion_id_campaign,
             conversion_id_publisher, conversion_external_txid, conversion_status,
             conversion_payout, conversion_revenue, conversion_currency,
             conversion_ip, conversion_raw_query, conversion_date)
        VALUES
            (:click, :click_date, :campaign, :publisher, :txid, :status,
             :payout, :revenue, :currency, INET6_ATON(:ip), :raw, UTC_TIMESTAMP(3))
        ON DUPLICATE KEY UPDATE
            conversion_status      = VALUES(conversion_status),
            conversion_payout      = VALUES(conversion_payout),
            conversion_revenue     = VALUES(conversion_revenue),
            conversion_raw_query   = VALUES(conversion_raw_query),
            conversion_date_update = UTC_TIMESTAMP(3),
            conversion_id          = LAST_INSERT_ID(conversion_id)
    SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'click'      => Ulid::toBinary($clickId),
        'click_date' => $click['click_date'],
        'campaign'   => $campaign['campaign_id'],
        'publisher'  => $click['click_id_publisher'],
        'txid'       => $txid,
        'status'     => $status,
        'payout'     => $payout,
        'revenue'    => $revenue,
        'currency'   => $campaign['campaign_currency'],
        'ip'         => $ip,
        'raw'        => $input !== [] ? json_encode($input, JSON_UNESCAPED_UNICODE) : null,
    ]);

    $conversionId = (int) $pdo->lastInsertId();
    $isNew        = $previousStatus === false;
    $statusMoved  = !$isNew && $previousStatus !== $status;

    // ── 4. Relais ───────────────────────────────────────────────────────────
    // On ne relaie que sur une conversion nouvelle ou sur un changement de
    // statut : un chargeback doit etre repercute a la plateforme externe, mais
    // un postback rejoue a l'identique ne doit rien renvoyer.
    $enqueued = ($isNew || $statusMoved) ? PostbackRouter::enqueue(
        $pdo,
        $conversionId,
        (int) $campaign['campaign_id'],
        $click['click_id_publisher'] !== null ? (int) $click['click_id_publisher'] : null,
        $status,
        [
            'clickid'          => $clickId,
            'external_clickid' => $click['click_external_id'],
            'campaign_id'      => $campaign['campaign_id'],
            'campaign_name'    => $campaign['campaign_name'],
            'publisher_id'     => $click['click_id_publisher'],
            'publisher_token'  => $click['publisher_token'],
            'sub1'             => $click['click_sub1'],
            'sub2'             => $click['click_sub2'],
            'sub3'             => $click['click_sub3'],
            'sub4'             => $click['click_sub4'],
            'sub5'             => $click['click_sub5'],
            'payout'           => number_format($payout, 4, '.', ''),
            'revenue'          => number_format($revenue, 4, '.', ''),
            'currency'         => $campaign['campaign_currency'],
            'txid'             => $txid,
            'status'           => $status,
            'timestamp'        => time(),
            'datetime'         => gmdate('Y-m-d H:i:s'),
            'ip'               => $click['click_ip_text'],
            'country'          => $click['click_country'],
            'ua'               => $click['click_user_agent'],
        ]
    ) : 0;

    $respond($reply, sprintf(
        '%s conversion=%d clic=%s txid=%s statut=%s relais=%d',
        $isNew ? 'CREE' : ($statusMoved ? 'STATUT' : 'REJOUE'),
        $conversionId,
        $clickId,
        $txid,
        $status,
        $enqueued
    ));
} catch (Throwable $e) {
    // Le money site recoit 200 malgre l'erreur : le faire retenter en boucle
    // sur une panne de notre cote n'aiderait personne. La trace, elle, reste.
    $respond('OK', 'ERREUR ' . $e->getMessage() . ' | clic=' . $clickId, 'erreur_interne', null,
        $e->getMessage());
}
