<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Controllers;

use App\Core\Database;
use App\Core\MacroEngine;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Les destinations de relai d'une campagne.
 *
 * Brancher une plateforme externe ne doit demander AUCUNE ligne de code : tout
 * se decrit par un template a macros. Si une integration n'y rentre pas, c'est
 * MacroEngine qu'il faut etendre, pas un `switch` par plateforme.
 */
final class PostbacksController
{
    public function __construct(private readonly Twig $view)
    {
    }

    public function save(Request $request, Response $response, array $args): Response
    {
        $body       = (array) $request->getParsedBody();
        $campaignId = (int) $args['id'];
        $pdo        = Database::get();

        $name = trim((string) ($body['cpb_name'] ?? ''));
        $url  = trim((string) ($body['cpb_url'] ?? ''));

        if ($name === '' || $url === '' || ($ssrf = self::rejectPrivateTarget($url)) !== null) {
            $_SESSION['flash_error'] = $name === '' || $url === ''
                ? 'Nom et URL sont obligatoires.'
                : $ssrf;

            return $response->withHeader('Location', '/app/campaigns/' . $campaignId)->withStatus(302);
        }

        // Une URL sans `{status}` ne peut pas repercuter une annulation : le
        // relai serait rigoureusement identique a celui de la validation, donc
        // supprime comme doublon — et la plateforme ne saurait de toute facon
        // pas les distinguer. On le dit a la saisie plutot que de laisser la
        // campagne perdre ses chargebacks en silence.
        $statuts = array_filter(array_map('trim', explode(',', strtolower(
            trim((string) ($body['cpb_on_status'] ?? 'approved')) ?: 'approved'
        ))));
        $autresQueApproved = array_diff($statuts, ['approved']);

        if ($autresQueApproved !== [] && !str_contains(strtolower($url . ($body['cpb_body'] ?? '')), '{status}')) {
            $_SESSION['flash_error'] = sprintf(
                'Destination enregistrée, mais elle ne répercutera pas « %s » : '
                . 'son URL ne contient pas la macro {status}, le second relai serait '
                . 'identique au premier et sera supprimé comme doublon.',
                implode(', ', $autresQueApproved)
            );
        }

        $data = [
            'campaign'  => $campaignId,
            'publisher' => ($body['cpb_id_publisher'] ?? '') !== '' ? (int) $body['cpb_id_publisher'] : null,
            'name'      => $name,
            'url'       => $url,
            'method'    => ($body['cpb_method'] ?? 'GET') === 'POST' ? 'POST' : 'GET',
            'body'      => trim((string) ($body['cpb_body'] ?? '')) ?: null,
            'headers'   => trim((string) ($body['cpb_headers'] ?? '')) ?: null,
            'on_status' => trim((string) ($body['cpb_on_status'] ?? 'approved')) ?: 'approved',
            'pattern'   => trim((string) ($body['cpb_success_pattern'] ?? '')) ?: null,
        ];

        if (($body['cpb_id'] ?? '') !== '') {
            $stmt = $pdo->prepare(
                'UPDATE t_campaign_postback
                    SET cpb_id_publisher = :publisher, cpb_name = :name, cpb_url = :url,
                        cpb_method = :method, cpb_body = :body, cpb_headers = :headers,
                        cpb_on_status = :on_status, cpb_success_pattern = :pattern
                  WHERE cpb_id = :id AND cpb_id_campaign = :campaign'
            );
            $stmt->execute($data + ['id' => (int) $body['cpb_id']]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO t_campaign_postback
                    (cpb_id_campaign, cpb_id_publisher, cpb_name, cpb_url, cpb_method,
                     cpb_body, cpb_headers, cpb_on_status, cpb_success_pattern)
                 VALUES (:campaign, :publisher, :name, :url, :method, :body, :headers, :on_status, :pattern)'
            );
            $stmt->execute($data);
        }

        return $response->withHeader('Location', '/app/campaigns/' . $campaignId)->withStatus(302);
    }

    public function toggle(Request $request, Response $response, array $args): Response
    {
        $stmt = Database::get()->prepare(
            "UPDATE t_campaign_postback
                SET cpb_status = IF(cpb_status = 'active', 'paused', 'active')
              WHERE cpb_id = :id AND cpb_id_campaign = :campaign"
        );
        $stmt->execute(['id' => (int) $args['postback'], 'campaign' => (int) $args['id']]);

        return $response->withHeader('Location', '/app/campaigns/' . (int) $args['id'])->withStatus(302);
    }

    /** Previsualise l'URL resolue avec un jeu de valeurs factices, sans appeler. */
    public function preview(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();

        $rendered = MacroEngine::render((string) ($body['url'] ?? ''), [
            'clickid'          => '01JVIGILDEMO0000000000000',
            'external_clickid' => 'EXT-123456',
            'campaign_id'      => 1,
            'campaign_name'    => 'Campagne de test',
            'publisher_id'     => 2,
            'publisher_token'  => 'pub7demo42',
            'sub1'             => 'source-A',
            'payout'           => '12.5000',
            'currency'         => 'EUR',
            'txid'             => 'TX-987',
            'status'           => 'approved',
            'timestamp'        => time(),
            'ip'               => '203.0.113.7',
            'country'          => 'FR',
        ]);

        $response->getBody()->write((string) json_encode([
            'url'     => $rendered,
            'unknown' => MacroEngine::unknownMacros((string) ($body['url'] ?? '')),
        ], JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Une URL de relai est saisie dans le back-office puis appelee par le
     * serveur : sans garde-fou, une adresse interne ferait appeler
     * l'infrastructure privee par le worker.
     */
    private static function rejectPrivateTarget(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return 'URL invalide.';
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return 'Seuls http et https sont acceptes.';
        }

        $host = $parts['host'];
        $ips  = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : @gethostbynamel($host);
        if ($ips === false || $ips === null) {
            // Nom qui ne resout pas encore : on laisse passer plutot que de
            // bloquer un branchement dont le DNS n'est pas propage. Le worker
            // refera la verification a l'envoi.
            return null;
        }

        foreach ($ips as $ip) {
            if (filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false) {
                return 'Cette URL pointe vers une adresse privee ou reservee (' . $ip . ').';
            }
        }

        return null;
    }
}
