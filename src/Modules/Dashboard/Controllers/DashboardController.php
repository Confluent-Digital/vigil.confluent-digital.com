<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Controllers;

use App\Core\Auth;
use App\Core\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class DashboardController
{
    public function __construct(private readonly Twig $view)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $pdo   = Database::get();
        $scope = Auth::publisherScope();

        // Le tableau de bord lit t_stats_hourly, jamais t_click : c'est ce qui
        // garde l'ecran rapide quand la table des clics atteint la centaine de
        // millions de lignes.
        $where = $scope !== null ? 'AND stats_id_publisher = :scope' : '';
        $args  = $scope !== null ? ['scope' => $scope] : [];

        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(stats_clicks), 0)        AS clicks,
                    COALESCE(SUM(stats_clicks_unique), 0) AS clicks_unique,
                    COALESCE(SUM(stats_conversions), 0)   AS conversions,
                    COALESCE(SUM(stats_payout), 0)        AS payout
               FROM t_stats_hourly
              WHERE stats_date_hour >= UTC_TIMESTAMP() - INTERVAL 7 DAY $where"
        );
        $stmt->execute($args);
        $totals = $stmt->fetch() ?: [];

        // Les compteurs de reference, eux, viennent des tables sources : c'est
        // ce qui permet de voir qu'une agregation est en retard plutot que de
        // lire un zero pour une absence de donnee.
        $stmt = $pdo->prepare(
            "SELECT (SELECT COUNT(*) FROM t_campaign WHERE campaign_status = 'active') AS campaigns,
                    (SELECT COUNT(*) FROM t_publisher WHERE publisher_status = 'active') AS publishers,
                    (SELECT COUNT(*) FROM t_postback_queue WHERE pq_status = 'pending')  AS queue_pending,
                    (SELECT COUNT(*) FROM t_postback_queue WHERE pq_status IN ('failed','abandoned')) AS queue_failed"
        );
        $stmt->execute();
        $counts = $stmt->fetch() ?: [];

        // Campagnes sans aucun controle d'authentification sur leur postback :
        // n'importe qui connaissant un clickid peut y crediter des conversions.
        $unprotected = $pdo->query(
            "SELECT campaign_id, campaign_name FROM t_campaign
              WHERE campaign_status = 'active'
                AND COALESCE(campaign_postback_secret, '') = ''
                AND COALESCE(campaign_postback_ips, '')    = ''
              ORDER BY campaign_name LIMIT 20"
        )->fetchAll();

        $recent = $pdo->prepare(
            "SELECT c.conversion_id, c.conversion_external_txid, c.conversion_status,
                    c.conversion_payout, c.conversion_currency, c.conversion_date,
                    ca.campaign_name, p.publisher_name
               FROM t_conversion c
               JOIN t_campaign ca ON ca.campaign_id = c.conversion_id_campaign
          LEFT JOIN t_publisher p ON p.publisher_id = c.conversion_id_publisher
              WHERE 1 = 1 " . ($scope !== null ? 'AND c.conversion_id_publisher = :scope' : '') . "
           ORDER BY c.conversion_date DESC LIMIT 10"
        );
        $recent->execute($args);

        return $this->view->render($response, 'pages/dashboard/index.html.twig', [
            'totals'      => $totals,
            'counts'      => $counts,
            'unprotected' => $unprotected,
            'recent'      => $recent->fetchAll(),
            'active_page' => 'dashboard',
        ]);
    }
}
