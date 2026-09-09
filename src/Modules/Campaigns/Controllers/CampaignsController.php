<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Controllers;

use App\Core\CampaignCache;
use App\Core\AppUrl;
use App\Core\Database;
use App\Core\Env;
use App\Core\MacroEngine;
use App\Core\Token;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class CampaignsController
{
    public function __construct(private readonly Twig $view)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $rows = Database::get()->query(
            "SELECT c.*, cl.client_name,
                    (SELECT COUNT(*) FROM t_campaign_publisher WHERE cp_id_campaign = c.campaign_id) AS nb_access,
                    (SELECT COUNT(*) FROM t_campaign_postback  WHERE cpb_id_campaign = c.campaign_id
                       AND cpb_status = 'active') AS nb_postbacks
               FROM t_campaign c
               JOIN t_client cl ON cl.client_id = c.campaign_id_client
              ORDER BY c.campaign_name"
        )->fetchAll();

        return $this->view->render($response, 'pages/campaigns/index.html.twig', [
            'campaigns'   => $rows,
            'active_page' => 'campaigns',
        ]);
    }

    public function form(Request $request, Response $response, array $args): Response
    {
        $pdo      = Database::get();
        $campaign = null;

        if (isset($args['id'])) {
            $stmt = $pdo->prepare('SELECT * FROM t_campaign WHERE campaign_id = :id');
            $stmt->execute(['id' => (int) $args['id']]);
            $campaign = $stmt->fetch() ?: null;
            if ($campaign === null) {
                return $response->withStatus(404);
            }
        }

        $clients = $pdo->query(
            "SELECT client_id, client_name FROM t_client WHERE client_status != 'archived' ORDER BY client_name"
        )->fetchAll();

        return $this->view->render($response, 'pages/campaigns/edit.html.twig', [
            'campaign'    => $campaign,
            'clients'     => $clients,
            'macros'      => MacroEngine::MACROS,
            'active_page' => 'campaigns',
        ]);
    }

    public function save(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $pdo  = Database::get();

        $name = trim((string) ($body['campaign_name'] ?? ''));
        $dest = trim((string) ($body['campaign_dest_url'] ?? ''));

        $errors = [];
        if ($name === '') {
            $errors[] = 'Le nom est obligatoire.';
        }
        if ($dest === '') {
            $errors[] = "L'URL de destination est obligatoire.";
        } elseif (!preg_match('#^https?://#i', $dest)) {
            // La destination est appelee par le navigateur du visiteur : un
            // schema autre que http(s) (javascript:, data:) en ferait un
            // vecteur d'attaque sous notre domaine.
            $errors[] = "L'URL de destination doit commencer par http:// ou https://.";
        }

        $unknown = MacroEngine::unknownMacros($dest);
        if ($unknown !== []) {
            $errors[] = 'Macros inconnues : {' . implode('}, {', $unknown) . '}.';
        }

        // Le `required` du <select> ne vaut que dans le navigateur : un POST
        // forge, ou une liste vide, passait avec `campaign_id_client = 0`. La
        // campagne devenait orpheline — sans client, donc sans secret partage,
        // et sans rien pour la rattacher a une facturation.
        $clientId = (int) ($body['campaign_id_client'] ?? 0);
        if ($clientId <= 0) {
            $errors[] = 'Le client est obligatoire.';
        } else {
            $existe = $pdo->prepare('SELECT 1 FROM t_client WHERE client_id = :id');
            $existe->execute(['id' => $clientId]);
            if ($existe->fetchColumn() === false) {
                $errors[] = 'Ce client n\'existe pas.';
            }
        }

        if ($errors !== []) {
            $clients = $pdo->query(
                "SELECT client_id, client_name FROM t_client WHERE client_status != 'archived' ORDER BY client_name"
            )->fetchAll();

            return $this->view->render(
                $response->withStatus(422),
                'pages/campaigns/edit.html.twig',
                [
                    'campaign' => $body, 'clients' => $clients, 'errors' => $errors,
                    'macros' => MacroEngine::MACROS, 'active_page' => 'campaigns',
                ]
            );
        }

        $data = [
            'client'   => $clientId,
            'name'     => $name,
            'status'   => in_array($body['campaign_status'] ?? '', ['active', 'paused', 'archived'], true)
                ? $body['campaign_status'] : 'paused',
            'dest'     => $dest,
            'payout'   => (float) str_replace(',', '.', (string) ($body['campaign_payout'] ?? 0)),
            'currency' => in_array($body['campaign_currency'] ?? '', ['EUR', 'USD', 'GBP'], true)
                ? $body['campaign_currency'] : 'EUR',
            'secret'   => trim((string) ($body['campaign_postback_secret'] ?? '')) ?: null,
            'ips'      => trim((string) ($body['campaign_postback_ips'] ?? '')) ?: null,
            'reply'    => trim((string) ($body['campaign_postback_response'] ?? '')) ?: 'OK',
            'stop'     => trim((string) ($body['campaign_date_stop'] ?? '')) ?: null,
        ];

        if (isset($args['id'])) {
            $stmt = $pdo->prepare(
                'UPDATE t_campaign SET campaign_id_client = :client, campaign_name = :name,
                        campaign_status = :status, campaign_dest_url = :dest,
                        campaign_payout = :payout, campaign_currency = :currency,
                        campaign_postback_secret = :secret, campaign_postback_ips = :ips,
                        campaign_postback_response = :reply, campaign_date_stop = :stop,
                        campaign_date_update = UTC_TIMESTAMP()
                  WHERE campaign_id = :id'
            );
            $stmt->execute($data + ['id' => (int) $args['id']]);
            $id = (int) $args['id'];
        } else {
            // Une campagne naît avec un secret : sans lui, elle accepterait
            // n'importe quelle conversion forgée dès sa mise en ligne.
            $data['secret'] ??= Token::secret();
            $stmt = $pdo->prepare(
                'INSERT INTO t_campaign
                    (campaign_id_client, campaign_name, campaign_status, campaign_dest_url,
                     campaign_payout, campaign_currency, campaign_postback_secret,
                     campaign_postback_ips, campaign_postback_response, campaign_date_stop)
                 VALUES (:client, :name, :status, :dest, :payout, :currency, :secret, :ips, :reply, :stop)'
            );
            $stmt->execute($data);
            $id = (int) $pdo->lastInsertId();
        }

        CampaignCache::invalidate();

        return $response->withHeader('Location', '/app/campaigns/' . $id . '/edit')->withStatus(302);
    }

    /** Ecran de detail : acces publishers (les liens) et destinations de relai. */
    public function detail(Request $request, Response $response, array $args): Response
    {
        $pdo = Database::get();
        $id  = (int) $args['id'];

        $stmt = $pdo->prepare(
            'SELECT c.*, cl.client_name FROM t_campaign c
               JOIN t_client cl ON cl.client_id = c.campaign_id_client
              WHERE c.campaign_id = :id'
        );
        $stmt->execute(['id' => $id]);
        $campaign = $stmt->fetch();
        if ($campaign === false) {
            return $response->withStatus(404);
        }

        $access = $pdo->prepare(
            'SELECT cp.*, p.publisher_name, p.publisher_token, p.publisher_status
               FROM t_campaign_publisher cp
               JOIN t_publisher p ON p.publisher_id = cp.cp_id_publisher
              WHERE cp.cp_id_campaign = :id ORDER BY p.publisher_name'
        );
        $access->execute(['id' => $id]);

        $postbacks = $pdo->prepare(
            'SELECT pb.*, p.publisher_name
               FROM t_campaign_postback pb
          LEFT JOIN t_publisher p ON p.publisher_id = pb.cpb_id_publisher
              WHERE pb.cpb_id_campaign = :id ORDER BY pb.cpb_name'
        );
        $postbacks->execute(['id' => $id]);

        $available = $pdo->prepare(
            "SELECT publisher_id, publisher_name FROM t_publisher
              WHERE publisher_status = 'active'
                AND publisher_id NOT IN (SELECT cp_id_publisher FROM t_campaign_publisher WHERE cp_id_campaign = :id)
              ORDER BY publisher_name"
        );
        $available->execute(['id' => $id]);

        return $this->view->render($response, 'pages/campaigns/detail.html.twig', [
            'campaign'    => $campaign,
            'access'      => $access->fetchAll(),
            'postbacks'   => $postbacks->fetchAll(),
            'available'   => $available->fetchAll(),
            'macros'      => MacroEngine::MACROS,
            'base_url'    => AppUrl::base($request),
            'url_ecart'   => AppUrl::matchesRequest($request) ? null : AppUrl::fromRequest($request),
            'active_page' => 'campaigns',
        ]);
    }
}
