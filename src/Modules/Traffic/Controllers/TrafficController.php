<?php

declare(strict_types=1);

namespace App\Modules\Traffic\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Ulid;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Clics, conversions, journal des relais.
 *
 * Ces trois listes sont les seules du back-office a lire les tables sources
 * plutot que le pre-agregat : ce sont des ecrans d'investigation, pas de
 * reporting. Elles sont donc systematiquement bornees dans le temps.
 */
final class TrafficController
{
    private const PAGE_SIZE = 100;

    public function __construct(private readonly Twig $view)
    {
    }

    public function clicks(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $days   = min(31, max(1, (int) ($params['days'] ?? 7)));
        $scope  = Auth::publisherScope();

        $sql = "SELECT c.click_id, c.click_date, c.click_external_id, c.click_is_unique,
                       c.click_is_bot, c.click_sub1, c.click_sub2,
                       INET6_NTOA(c.click_ip) AS ip, c.click_user_agent,
                       ca.campaign_name, p.publisher_name,
                       (SELECT COUNT(*) FROM t_conversion v
                         WHERE v.conversion_id_click = c.click_id) AS nb_conversions
                  FROM t_click c
                  JOIN t_campaign  ca ON ca.campaign_id  = c.click_id_campaign
                  JOIN t_publisher p  ON p.publisher_id  = c.click_id_publisher
                 WHERE c.click_date >= UTC_TIMESTAMP() - INTERVAL :days DAY";

        $args = ['days' => $days];
        if ($scope !== null) {
            $sql .= ' AND c.click_id_publisher = :scope';
            $args['scope'] = $scope;
        }
        if (($params['campaign'] ?? '') !== '') {
            $sql .= ' AND c.click_id_campaign = :campaign';
            $args['campaign'] = (int) $params['campaign'];
        }
        $sql .= ' ORDER BY c.click_date DESC LIMIT ' . self::PAGE_SIZE;

        $stmt = Database::get()->prepare($sql);
        $stmt->execute($args);

        $rows = array_map(static function (array $row): array {
            $row['click_ulid'] = Ulid::fromBinary($row['click_id']);
            unset($row['click_id']);
            return $row;
        }, $stmt->fetchAll());

        return $this->view->render($response, 'pages/traffic/clicks.html.twig', [
            'clicks'      => $rows,
            'days'        => $days,
            'campaigns'   => $this->campaignList(),
            'limit'       => self::PAGE_SIZE,
            'active_page' => 'clicks',
        ]);
    }

    public function conversions(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $days   = min(90, max(1, (int) ($params['days'] ?? 30)));
        $scope  = Auth::publisherScope();

        $sql = "SELECT v.*, ca.campaign_name, p.publisher_name,
                       (SELECT COUNT(*) FROM t_postback_queue q
                         WHERE q.pq_id_conversion = v.conversion_id) AS nb_relais,
                       (SELECT COUNT(*) FROM t_postback_queue q
                         WHERE q.pq_id_conversion = v.conversion_id
                           AND q.pq_status IN ('failed','abandoned')) AS nb_echecs
                  FROM t_conversion v
                  JOIN t_campaign  ca ON ca.campaign_id  = v.conversion_id_campaign
             LEFT JOIN t_publisher p  ON p.publisher_id  = v.conversion_id_publisher
                 WHERE v.conversion_date >= UTC_TIMESTAMP() - INTERVAL :days DAY";

        $args = ['days' => $days];
        if ($scope !== null) {
            $sql .= ' AND v.conversion_id_publisher = :scope';
            $args['scope'] = $scope;
        }
        if (($params['status'] ?? '') !== '') {
            $sql .= ' AND v.conversion_status = :status';
            $args['status'] = (string) $params['status'];
        }
        $sql .= ' ORDER BY v.conversion_date DESC LIMIT ' . self::PAGE_SIZE;

        $stmt = Database::get()->prepare($sql);
        $stmt->execute($args);

        $rows = array_map(static function (array $row): array {
            $row['click_ulid'] = $row['conversion_id_click'] !== null
                ? Ulid::fromBinary($row['conversion_id_click']) : null;
            unset($row['conversion_id_click']);
            return $row;
        }, $stmt->fetchAll());

        return $this->view->render($response, 'pages/traffic/conversions.html.twig', [
            'conversions' => $rows,
            'days'        => $days,
            'status'      => $params['status'] ?? '',
            'limit'       => self::PAGE_SIZE,
            'active_page' => 'conversions',
        ]);
    }

    /** Journal des relais : code HTTP et corps de reponse, succes ET echecs. */
    public function queue(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();

        $sql = "SELECT q.*, pb.cpb_name, ca.campaign_name, v.conversion_external_txid
                  FROM t_postback_queue q
             LEFT JOIN t_campaign_postback pb ON pb.cpb_id = q.pq_id_campaign_postback
             LEFT JOIN t_conversion v ON v.conversion_id = q.pq_id_conversion
             LEFT JOIN t_campaign ca ON ca.campaign_id = v.conversion_id_campaign
                 WHERE 1 = 1";
        $args = [];

        if (($params['status'] ?? '') !== '') {
            $sql .= ' AND q.pq_status = :status';
            $args['status'] = (string) $params['status'];
        }
        $sql .= ' ORDER BY q.pq_id DESC LIMIT ' . self::PAGE_SIZE;

        $stmt = Database::get()->prepare($sql);
        $stmt->execute($args);

        return $this->view->render($response, 'pages/traffic/queue.html.twig', [
            'queue'       => $stmt->fetchAll(),
            'status'      => $params['status'] ?? '',
            'limit'       => self::PAGE_SIZE,
            'active_page' => 'queue',
        ]);
    }

    /**
     * Remet les relais d'une conversion en attente.
     *
     * Sans ce bouton, la moindre panne d'une plateforme externe se rattrape en
     * SQL a la main, sous pression, en production.
     */
    public function replay(Request $request, Response $response, array $args): Response
    {
        if (Auth::publisherScope() !== null) {
            return $response->withStatus(403);
        }

        $stmt = Database::get()->prepare(
            "UPDATE t_postback_queue
                SET pq_status = 'pending', pq_attempts = 0,
                    pq_next_try_at = UTC_TIMESTAMP(), pq_error = NULL
              WHERE pq_id_conversion = :id"
        );
        $stmt->execute(['id' => (int) $args['id']]);

        $_SESSION['flash_ok'] = $stmt->rowCount() . ' relai(s) remis en attente.';

        return $response
            ->withHeader('Location', $request->getHeaderLine('Referer') ?: '/app/conversions')
            ->withStatus(302);
    }

    /**
     * Liens morts : les 404 du chemin de tracking.
     *
     * Ce n'est pas une liste de pages manquantes, c'est une liste de liens EN
     * CIRCULATION qui envoient du trafic dans le vide. Un lien dont la campagne
     * est en pause se repare en un clic ; encore faut-il savoir qu'il existe.
     */
    public function deadLinks(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $days   = min(90, max(1, (int) ($params['days'] ?? 7)));

        $sql = "SELECT m.*, c.campaign_name, c.campaign_status, p.publisher_name,
                       cp.cp_status
                  FROM t_link_miss m
             LEFT JOIN t_campaign  c  ON c.campaign_id  = m.miss_id_campaign
             LEFT JOIN t_publisher p  ON p.publisher_id = m.miss_id_publisher
             LEFT JOIN t_campaign_publisher cp ON cp.cp_token = m.miss_token
                 WHERE m.miss_date >= UTC_DATE() - INTERVAL :days DAY";

        $args = ['days' => $days];
        if (($params['reason'] ?? '') !== '') {
            $sql .= ' AND m.miss_reason = :reason';
            $args['reason'] = (string) $params['reason'];
        }

        // Tries par volume : un lien mort a dix mille visites merite qu'on s'en
        // occupe avant celui qui en a trois.
        $sql .= ' ORDER BY m.miss_count DESC, m.miss_last_at DESC LIMIT ' . self::PAGE_SIZE;

        $stmt = Database::get()->prepare($sql);
        $stmt->execute($args);
        $lignes = $stmt->fetchAll();

        $totaux = Database::get()->prepare(
            'SELECT miss_reason, SUM(miss_count) AS clics, COUNT(*) AS liens
               FROM t_link_miss
              WHERE miss_date >= UTC_DATE() - INTERVAL :days DAY
           GROUP BY miss_reason ORDER BY clics DESC'
        );
        $totaux->execute(['days' => $days]);

        // Les postbacks refuses partagent la meme nature que les liens morts :
        // un echec silencieux sur le chemin chaud, que rien ne signale. Ils
        // vivent donc sur le meme ecran.
        $refus = Database::get()->prepare(
            'SELECT m.*, c.campaign_name
               FROM t_postback_miss m
          LEFT JOIN t_campaign c ON c.campaign_id = m.pmiss_id_campaign AND m.pmiss_id_campaign > 0
              WHERE m.pmiss_date >= UTC_DATE() - INTERVAL :days DAY
           ORDER BY m.pmiss_count DESC, m.pmiss_last_at DESC
              LIMIT 50'
        );
        $refus->execute(['days' => $days]);

        return $this->view->render($response, 'pages/traffic/dead_links.html.twig', [
            'refus'         => $refus->fetchAll(),
            'raisons_refus' => \App\Core\PostbackMiss::RAISONS,
            'liens'       => $lignes,
            'totaux'      => $totaux->fetchAll(),
            'raisons'     => \App\Core\LinkMiss::RAISONS,
            'days'        => $days,
            'reason'      => $params['reason'] ?? '',
            'limit'       => self::PAGE_SIZE,
            'active_page' => 'dead',
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function campaignList(): array
    {
        return Database::get()->query(
            "SELECT campaign_id, campaign_name FROM t_campaign
              WHERE campaign_status != 'archived' ORDER BY campaign_name"
        )->fetchAll();
    }
}
