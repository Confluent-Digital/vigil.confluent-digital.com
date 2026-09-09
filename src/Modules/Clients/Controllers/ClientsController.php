<?php

declare(strict_types=1);

namespace App\Modules\Clients\Controllers;

use App\Core\AppUrl;
use App\Core\Database;
use App\Core\Token;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class ClientsController
{
    public function __construct(private readonly Twig $view)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $rows = Database::get()->query(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM t_campaign WHERE campaign_id_client = c.client_id) AS nb_campaigns
               FROM t_client c
              ORDER BY c.client_name"
        )->fetchAll();

        return $this->view->render($response, 'pages/clients/index.html.twig', [
            'clients'     => $rows,
            'active_page' => 'clients',
        ]);
    }

    public function form(Request $request, Response $response, array $args): Response
    {
        $client = null;
        if (isset($args['id'])) {
            $stmt = Database::get()->prepare('SELECT * FROM t_client WHERE client_id = :id');
            $stmt->execute(['id' => (int) $args['id']]);
            $client = $stmt->fetch() ?: null;
            if ($client === null) {
                return $response->withStatus(404);
            }
        }

        return $this->view->render($response, 'pages/clients/edit.html.twig', [
            'client'      => $client,
            'base_url'    => AppUrl::base($request),
            'nb_campaigns' => $client === null ? 0 : $this->countCampaigns((int) $client['client_id']),
            'active_page' => 'clients',
        ]);
    }

    public function save(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $data = [
            'name'   => trim((string) ($body['client_name'] ?? '')),
            'status' => in_array($body['client_status'] ?? '', ['active', 'paused', 'archived'], true)
                ? $body['client_status'] : 'active',
            'email'  => trim((string) ($body['client_contact_email'] ?? '')) ?: null,
            'notes'  => trim((string) ($body['client_notes'] ?? '')) ?: null,
        ];

        if ($data['name'] === '') {
            return $this->view->render(
                $response->withStatus(422),
                'pages/clients/edit.html.twig',
                ['client' => $body, 'error' => 'Le nom est obligatoire.', 'active_page' => 'clients']
            );
        }

        $pdo = Database::get();
        if (isset($args['id'])) {
            $stmt = $pdo->prepare(
                'UPDATE t_client SET client_name = :name, client_status = :status,
                        client_contact_email = :email, client_notes = :notes
                  WHERE client_id = :id'
            );
            $stmt->execute($data + ['id' => (int) $args['id']]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO t_client
                     (client_name, client_status, client_contact_email, client_notes,
                      client_postback_secret)
                 VALUES (:name, :status, :email, :notes, :secret)'
            );
            $stmt->execute($data + ['secret' => Token::secret()]);
        }

        return $response->withHeader('Location', '/app/clients')->withStatus(302);
    }

    /**
     * Regenere le secret partage. **Tous** les postbacks du client cessent
     * d'etre acceptes tant qu'il n'a pas mis a jour son money site : les
     * conversions seront refusees, avec un `200` rendu quand meme. A ne faire
     * qu'en cas de fuite, et en prevenant le client d'abord.
     */
    public function regenerateSecret(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        Database::get()
            ->prepare('UPDATE t_client SET client_postback_secret = :s WHERE client_id = :id')
            ->execute(['s' => Token::secret(), 'id' => $id]);

        return $response->withHeader('Location', '/app/clients/' . $id . '/edit')->withStatus(302);
    }

    private function countCampaigns(int $clientId): int
    {
        $stmt = Database::get()->prepare(
            'SELECT COUNT(*) FROM t_campaign WHERE campaign_id_client = :id'
        );
        $stmt->execute(['id' => $clientId]);

        return (int) $stmt->fetchColumn();
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $pdo = Database::get();
        $id  = (int) $args['id'];

        // On archive, on ne supprime pas : les clics et conversions historiques
        // referencent ce client par ses campagnes, et un rapport sur une
        // periode passee doit continuer a s'afficher.
        $stmt = $pdo->prepare("UPDATE t_client SET client_status = 'archived' WHERE client_id = :id");
        $stmt->execute(['id' => $id]);

        return $response->withHeader('Location', '/app/clients')->withStatus(302);
    }
}
