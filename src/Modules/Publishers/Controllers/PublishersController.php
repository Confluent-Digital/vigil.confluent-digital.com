<?php

declare(strict_types=1);

namespace App\Modules\Publishers\Controllers;

use App\Core\CampaignCache;
use App\Core\Database;
use App\Core\MacroEngine;
use App\Core\Token;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class PublishersController
{
    public function __construct(private readonly Twig $view)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $rows = Database::get()->query(
            "SELECT p.*,
                    (SELECT COUNT(*) FROM t_campaign_publisher WHERE cp_id_publisher = p.publisher_id) AS nb_access
               FROM t_publisher p
              ORDER BY p.publisher_name"
        )->fetchAll();

        return $this->view->render($response, 'pages/publishers/index.html.twig', [
            'publishers'  => $rows,
            'active_page' => 'publishers',
        ]);
    }

    public function form(Request $request, Response $response, array $args): Response
    {
        $publisher = null;
        if (isset($args['id'])) {
            $stmt = Database::get()->prepare('SELECT * FROM t_publisher WHERE publisher_id = :id');
            $stmt->execute(['id' => (int) $args['id']]);
            $publisher = $stmt->fetch() ?: null;
            if ($publisher === null) {
                return $response->withStatus(404);
            }
        }

        // Les destinations de relai propres au publisher : son pixel de
        // conversion, declare une fois et declenche sur toutes ses conversions.
        $pixels = [];
        if ($publisher !== null) {
            $stmt = Database::get()->prepare(
                'SELECT pb.*, c.campaign_name
                   FROM t_campaign_postback pb
              LEFT JOIN t_campaign c ON c.campaign_id = pb.cpb_id_campaign
                  WHERE pb.cpb_id_publisher = :id
               ORDER BY pb.cpb_id_campaign IS NOT NULL, pb.cpb_name'
            );
            $stmt->execute(['id' => (int) $args['id']]);
            $pixels = $stmt->fetchAll();
        }

        return $this->view->render($response, 'pages/publishers/edit.html.twig', [
            'publisher'   => $publisher,
            'pixels'      => $pixels,
            'macros'      => MacroEngine::TRACKING_MACROS,
            'active_page' => 'publishers',
        ]);
    }

    public function save(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $name = trim((string) ($body['publisher_name'] ?? ''));

        if ($name === '') {
            return $this->view->render(
                $response->withStatus(422),
                'pages/publishers/edit.html.twig',
                ['publisher' => $body, 'error' => 'Le nom est obligatoire.', 'active_page' => 'publishers']
            );
        }

        $data = [
            'name'   => $name,
            'status' => in_array($body['publisher_status'] ?? '', ['active', 'paused', 'archived'], true)
                ? $body['publisher_status'] : 'active',
            'email'  => trim((string) ($body['publisher_contact_email'] ?? '')) ?: null,
            'notes'  => trim((string) ($body['publisher_notes'] ?? '')) ?: null,
        ];

        $pdo = Database::get();
        if (isset($args['id'])) {
            $stmt = $pdo->prepare(
                'UPDATE t_publisher SET publisher_name = :name, publisher_status = :status,
                        publisher_contact_email = :email, publisher_notes = :notes
                  WHERE publisher_id = :id'
            );
            $stmt->execute($data + ['id' => (int) $args['id']]);
        } else {
            $data['token'] = Token::generate(10);
            $stmt = $pdo->prepare(
                'INSERT INTO t_publisher
                    (publisher_name, publisher_token, publisher_status,
                     publisher_contact_email, publisher_notes)
                 VALUES (:name, :token, :status, :email, :notes)'
            );
            $stmt->execute($data);
        }

        // Le statut du publisher conditionne la resolution des liens : sans
        // invalidation, une mise en pause mettrait jusqu'a 60 s a prendre.
        CampaignCache::invalidate();

        return $response->withHeader('Location', '/app/publishers')->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $stmt = Database::get()->prepare(
            "UPDATE t_publisher SET publisher_status = 'archived' WHERE publisher_id = :id"
        );
        $stmt->execute(['id' => (int) $args['id']]);
        CampaignCache::invalidate();

        return $response->withHeader('Location', '/app/publishers')->withStatus(302);
    }
}
