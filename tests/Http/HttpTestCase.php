<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests du chemin chaud, exerces par HTTP.
 *
 * `c.php` et `pb.php` lisent les superglobales et posent des en-tetes : les
 * inclure depuis PHPUnit ne prouverait ni le code 302, ni `Cache-Control`, ni
 * le comportement reel derriere nginx et PHP-FPM. On tape donc le serveur.
 *
 * Consequence : ces tests ecrivent dans la base de DEVELOPPEMENT, celle que
 * sert le conteneur — pas dans la base de test. Ils nettoient donc eux-memes
 * ce qu'ils ont cree, et se marquent par un prefixe reconnaissable.
 */
abstract class HttpTestCase extends TestCase
{
    protected const MARQUEUR = 'PHPUNIT-';

    /**
     * Depuis le conteneur PHP, `127.0.0.1` designe le conteneur lui-meme : il
     * faut viser nginx par son nom sur le reseau Docker. Surchargeable par
     * `TEST_BASE_URL` pour lancer la suite depuis l'hote.
     */
    protected function baseUrl(): string
    {
        return rtrim((string) \App\Core\Env::get('TEST_BASE_URL', 'http://vigil_nginx'), '/');
    }

    protected PDO $pdo;

    /** @var int[] identifiants crees, a supprimer */
    private array $aNettoyer = ['client' => [], 'publisher' => [], 'campaign' => []];

    protected function setUp(): void
    {
        parent::setUp();

        // La base de test n'est pas celle que sert le serveur : on repointe.
        $_ENV['DB_NAME'] = str_replace('_test', '', (string) ($_ENV['DB_NAME'] ?? 'bd_vigil'));
        Database::reset();
        $this->pdo = Database::get();

        if ($this->head($this->baseUrl() . '/health') === 0) {
            self::markTestSkipped('Le serveur ne repond pas sur ' . $this->baseUrl());
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->aNettoyer['campaign'] as $id) {
            $this->pdo->prepare('DELETE FROM t_click WHERE click_id_campaign = :i')->execute(['i' => $id]);
            $this->pdo->prepare('DELETE FROM t_conversion WHERE conversion_id_campaign = :i')->execute(['i' => $id]);
            $this->pdo->prepare('DELETE FROM t_campaign_publisher WHERE cp_id_campaign = :i')->execute(['i' => $id]);
            $this->pdo->prepare('DELETE FROM t_campaign_postback WHERE cpb_id_campaign = :i')->execute(['i' => $id]);
            $this->pdo->prepare('DELETE FROM t_campaign WHERE campaign_id = :i')->execute(['i' => $id]);
        }
        foreach ($this->aNettoyer['publisher'] as $id) {
            $this->pdo->prepare('DELETE FROM t_publisher WHERE publisher_id = :i')->execute(['i' => $id]);
        }
        foreach ($this->aNettoyer['client'] as $id) {
            $this->pdo->prepare('DELETE FROM t_client WHERE client_id = :i')->execute(['i' => $id]);
        }

        \App\Core\CampaignCache::invalidate();
        Database::reset();

        parent::tearDown();
    }

    /** @return array{token: string, secret: string, campaign_id: int} */
    protected function makeCampaign(string $destUrl, array $overrides = []): array
    {
        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
        $secret = 'sec' . $suffix;

        $this->pdo->prepare('INSERT INTO t_client (client_name) VALUES (:n)')
            ->execute(['n' => self::MARQUEUR . $suffix]);
        $clientId = (int) $this->pdo->lastInsertId();
        $this->aNettoyer['client'][] = $clientId;

        $this->pdo->prepare('INSERT INTO t_publisher (publisher_name, publisher_token) VALUES (:n, :t)')
            ->execute(['n' => self::MARQUEUR . $suffix, 't' => 'pu' . $suffix]);
        $publisherId = (int) $this->pdo->lastInsertId();
        $this->aNettoyer['publisher'][] = $publisherId;

        $this->pdo->prepare(
            'INSERT INTO t_campaign
                 (campaign_id_client, campaign_name, campaign_status, campaign_dest_url,
                  campaign_payout, campaign_postback_secret)
             VALUES (:c, :n, :s, :u, 10, :k)'
        )->execute([
            'c' => $clientId,
            'n' => self::MARQUEUR . $suffix,
            's' => $overrides['status'] ?? 'active',
            'u' => $destUrl,
            'k' => $overrides['secret'] ?? $secret,
        ]);
        $campaignId = (int) $this->pdo->lastInsertId();
        $this->aNettoyer['campaign'][] = $campaignId;

        $token = 'ht' . $suffix;
        $this->pdo->prepare(
            'INSERT INTO t_campaign_publisher (cp_id_campaign, cp_id_publisher, cp_token, cp_status)
             VALUES (:c, :p, :t, :s)'
        )->execute([
            'c' => $campaignId, 'p' => $publisherId, 't' => $token,
            's' => $overrides['cp_status'] ?? 'active',
        ]);

        // Le chemin chaud sert la campagne depuis APCu : sans invalidation, le
        // processus PHP-FPM continuerait de repondre 404 sur ce token neuf.
        $this->get('/health');
        \App\Core\CampaignCache::invalidate();

        return ['token' => $token, 'secret' => $overrides['secret'] ?? $secret, 'campaign_id' => $campaignId];
    }

    protected function countRows(string $table, string $where = '1', array $args = []): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM $table WHERE $where");
        $stmt->execute($args);

        return (int) $stmt->fetchColumn();
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    protected function get(string $path, array $headers = []): array
    {
        $ch = curl_init($this->baseUrl() . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (PHPUnit)',
        ]);

        $reponse = (string) curl_exec($ch);
        $taille  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $entetes = [];
        foreach (explode("\r\n", substr($reponse, 0, $taille)) as $ligne) {
            if (str_contains($ligne, ':')) {
                [$k, $v] = explode(':', $ligne, 2);
                $entetes[strtolower(trim($k))] = trim($v);
            }
        }

        return ['status' => $status, 'headers' => $entetes, 'body' => substr($reponse, $taille)];
    }

    private function head(string $url): int
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 3]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return $code;
    }
}
