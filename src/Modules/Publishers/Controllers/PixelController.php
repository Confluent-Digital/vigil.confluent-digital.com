<?php

declare(strict_types=1);

namespace App\Modules\Publishers\Controllers;

use App\Core\Database;
use App\Core\MacroEngine;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Le pixel de conversion d'un publisher.
 *
 * C'est une destination de relai comme une autre — meme table, meme moteur de
 * macros, meme file d'envoi, memes reessais — mais dont la portee est le
 * publisher et non la campagne : `cpb_id_campaign` reste NULL, et le pixel se
 * declenche sur TOUTES les conversions de ce publisher.
 *
 * Sans cette portee, il fallait recopier la meme ligne sur chaque campagne ou
 * le publisher travaille. Dix campagnes, dix lignes ; il change son pixel, dix
 * modifications ; on en oublie une, ses conversions cessent de remonter sans
 * que rien ne le signale.
 */
final class PixelController
{
    public function save(Request $request, Response $response, array $args): Response
    {
        $body        = (array) $request->getParsedBody();
        $publisherId = (int) $args['id'];

        $name = trim((string) ($body['cpb_name'] ?? '')) ?: 'Pixel de conversion';
        $url  = trim((string) ($body['cpb_url'] ?? ''));

        if ($url === '') {
            $_SESSION['flash_error'] = "L'URL du pixel est obligatoire.";

            return $this->back($response, $publisherId);
        }

        if (($motif = self::rejectPrivateTarget($url)) !== null) {
            $_SESSION['flash_error'] = $motif;

            return $this->back($response, $publisherId);
        }

        $inconnues = MacroEngine::unknownMacros($url . ' ' . (string) ($body['cpb_body'] ?? ''));
        if ($inconnues !== []) {
            $_SESSION['flash_error'] = 'Macros inconnues : {' . implode('}, {', $inconnues) . '}.';

            return $this->back($response, $publisherId);
        }

        $statuts = array_filter(array_map('trim', explode(',', strtolower(
            trim((string) ($body['cpb_on_status'] ?? 'approved')) ?: 'approved'
        ))));

        // Meme garde que pour une destination de campagne : sans `{status}`,
        // le relai d'une annulation serait identique a celui de la validation,
        // donc supprime comme doublon — et le publisher ne pourrait de toute
        // facon pas les distinguer.
        if (array_diff($statuts, ['approved']) !== []
            && !str_contains(strtolower($url . (string) ($body['cpb_body'] ?? '')), '{status}')) {
            $_SESSION['flash_error'] = 'Pixel enregistré, mais il ne répercutera pas les annulations : '
                . 'son URL ne contient pas la macro {status}.';
        }

        $data = [
            'publisher' => $publisherId,
            'name'      => $name,
            'url'       => $url,
            'method'    => ($body['cpb_method'] ?? 'GET') === 'POST' ? 'POST' : 'GET',
            'body'      => trim((string) ($body['cpb_body'] ?? '')) ?: null,
            'headers'   => trim((string) ($body['cpb_headers'] ?? '')) ?: null,
            'on_status' => implode(',', $statuts) ?: 'approved',
            'pattern'   => trim((string) ($body['cpb_success_pattern'] ?? '')) ?: null,
        ];

        $pdo = Database::get();

        if (($body['cpb_id'] ?? '') !== '') {
            $stmt = $pdo->prepare(
                'UPDATE t_campaign_postback
                    SET cpb_name = :name, cpb_url = :url, cpb_method = :method,
                        cpb_body = :body, cpb_headers = :headers,
                        cpb_on_status = :on_status, cpb_success_pattern = :pattern
                  WHERE cpb_id = :id AND cpb_id_publisher = :publisher'
            );
            $stmt->execute($data + ['id' => (int) $body['cpb_id']]);
        } else {
            // cpb_id_campaign reste NULL : c'est ce qui donne la portee
            // publisher, toutes campagnes confondues.
            $stmt = $pdo->prepare(
                'INSERT INTO t_campaign_postback
                    (cpb_id_campaign, cpb_id_publisher, cpb_name, cpb_url, cpb_method,
                     cpb_body, cpb_headers, cpb_on_status, cpb_success_pattern)
                 VALUES (NULL, :publisher, :name, :url, :method, :body, :headers, :on_status, :pattern)'
            );
            $stmt->execute($data);
        }

        return $this->back($response, $publisherId);
    }

    public function toggle(Request $request, Response $response, array $args): Response
    {
        Database::get()->prepare(
            "UPDATE t_campaign_postback
                SET cpb_status = IF(cpb_status = 'active', 'paused', 'active')
              WHERE cpb_id = :id AND cpb_id_publisher = :publisher"
        )->execute(['id' => (int) $args['pixel'], 'publisher' => (int) $args['id']]);

        return $this->back($response, (int) $args['id']);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        // Suppression franche : contrairement a un client ou une campagne, une
        // destination ne porte aucun historique — la file d'envoi conserve
        // l'URL exacte de chaque relai deja parti.
        Database::get()->prepare(
            'DELETE FROM t_campaign_postback
              WHERE cpb_id = :id AND cpb_id_publisher = :publisher AND cpb_id_campaign IS NULL'
        )->execute(['id' => (int) $args['pixel'], 'publisher' => (int) $args['id']]);

        return $this->back($response, (int) $args['id']);
    }

    private function back(Response $response, int $publisherId): Response
    {
        return $response
            ->withHeader('Location', '/app/publishers/' . $publisherId . '/edit')
            ->withStatus(302);
    }

    /** Meme garde-fou SSRF que pour une destination de campagne. */
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
            return null;
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return 'Cette URL pointe vers une adresse privee ou reservee (' . $ip . ').';
            }
        }

        return null;
    }
}
