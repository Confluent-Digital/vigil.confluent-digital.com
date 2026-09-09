<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Controllers;

use App\Core\CampaignCache;
use App\Core\Database;
use App\Core\Token;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Les acces publishers d'une campagne — c'est-a-dire les liens de tracking.
 *
 * Le token est genere ici et nulle part ailleurs : c'est lui qui porte le
 * couple campagne x publisher, donc l'autorisation.
 */
final class AccessController
{
    public function add(Request $request, Response $response, array $args): Response
    {
        $body        = (array) $request->getParsedBody();
        $campaignId  = (int) $args['id'];
        $publisherId = (int) ($body['publisher_id'] ?? 0);

        if ($publisherId > 0) {
            $payout = trim((string) ($body['cp_payout'] ?? ''));

            $stmt = Database::get()->prepare(
                'INSERT INTO t_campaign_publisher (cp_id_campaign, cp_id_publisher, cp_token, cp_payout)
                 VALUES (:campaign, :publisher, :token, :payout)
                 ON DUPLICATE KEY UPDATE cp_status = \'active\''
            );
            $stmt->execute([
                'campaign'  => $campaignId,
                'publisher' => $publisherId,
                'token'     => Token::generate(12),
                'payout'    => $payout === '' ? null : (float) str_replace(',', '.', $payout),
            ]);

            CampaignCache::invalidate();
        }

        return $response->withHeader('Location', '/app/campaigns/' . $campaignId)->withStatus(302);
    }

    public function toggle(Request $request, Response $response, array $args): Response
    {
        $stmt = Database::get()->prepare(
            "UPDATE t_campaign_publisher
                SET cp_status = IF(cp_status = 'active', 'paused', 'active')
              WHERE cp_id = :id AND cp_id_campaign = :campaign"
        );
        $stmt->execute(['id' => (int) $args['access'], 'campaign' => (int) $args['id']]);

        CampaignCache::invalidate();

        return $response->withHeader('Location', '/app/campaigns/' . (int) $args['id'])->withStatus(302);
    }

    /**
     * Regenere le token d'un lien. Coupe le lien en circulation : les clics qui
     * arrivent encore sur l'ancien token tombent en 404. A n'utiliser que si le
     * lien a fuite.
     */
    public function regenerate(Request $request, Response $response, array $args): Response
    {
        $stmt = Database::get()->prepare(
            'UPDATE t_campaign_publisher SET cp_token = :token
              WHERE cp_id = :id AND cp_id_campaign = :campaign'
        );
        $stmt->execute([
            'token'    => Token::generate(12),
            'id'       => (int) $args['access'],
            'campaign' => (int) $args['id'],
        ]);

        CampaignCache::invalidate();

        return $response->withHeader('Location', '/app/campaigns/' . (int) $args['id'])->withStatus(302);
    }
}
