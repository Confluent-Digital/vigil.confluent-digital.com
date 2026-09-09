<?php

declare(strict_types=1);

namespace App\Tests;

use App\Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base des tests qui touchent la base.
 *
 * Chaque test s'execute dans une transaction annulee a la fin : aucun etat ne
 * fuit d'un test au suivant, et il n'y a pas de fixtures a nettoyer.
 *
 * Attention : PDO **ne sait pas** imbriquer les transactions, il leve « There
 * is already an active transaction ». Tout code applicatif appele depuis un
 * test doit donc verifier `inTransaction()` avant d'ouvrir la sienne. Une
 * instruction DDL (ALTER TABLE) provoque en plus un commit implicite : les
 * tests de partitionnement ne peuvent pas etre transactionnels.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = Database::get();

        // Garde-fou : une erreur de configuration ferait tourner la suite sur
        // la base de developpement, qu'elle viderait.
        $name = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!str_ends_with($name, '_test')) {
            self::fail("La suite doit tourner sur une base *_test, pas sur « $name ».");
        }

        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }

        parent::tearDown();
    }

    /** Cree un jeu client + publisher + campagne + acces, et rend les identifiants. */
    protected function makeCampaign(array $overrides = []): array
    {
        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);

        $this->pdo->prepare('INSERT INTO t_client (client_name) VALUES (:n)')
            ->execute(['n' => 'Test ' . $suffix]);
        $clientId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO t_publisher (publisher_name, publisher_token) VALUES (:n, :t)'
        )->execute(['n' => 'Pub ' . $suffix, 't' => 'p' . $suffix]);
        $publisherId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO t_campaign
                 (campaign_id_client, campaign_name, campaign_status, campaign_dest_url,
                  campaign_payout, campaign_postback_secret)
             VALUES (:c, :n, :s, :u, :p, :k)'
        )->execute([
            'c' => $clientId,
            'n' => 'Camp ' . $suffix,
            's' => $overrides['campaign_status'] ?? 'active',
            'u' => $overrides['campaign_dest_url'] ?? 'https://money.test/?c={clickid}',
            'p' => $overrides['campaign_payout'] ?? 10,
            'k' => $overrides['campaign_postback_secret'] ?? 'secret-' . $suffix,
        ]);
        $campaignId = (int) $this->pdo->lastInsertId();

        $token = 'tk' . $suffix;
        $this->pdo->prepare(
            'INSERT INTO t_campaign_publisher (cp_id_campaign, cp_id_publisher, cp_token)
             VALUES (:c, :p, :t)'
        )->execute(['c' => $campaignId, 'p' => $publisherId, 't' => $token]);

        return [
            'client_id'    => $clientId,
            'publisher_id' => $publisherId,
            'campaign_id'  => $campaignId,
            'token'        => $token,
            'secret'       => $overrides['campaign_postback_secret'] ?? 'secret-' . $suffix,
        ];
    }

    /** Insere un clic et rend son ULID. */
    protected function makeClick(array $ctx, array $overrides = []): string
    {
        $ulid = \App\Core\Ulid::generate();

        $this->pdo->prepare(
            'INSERT INTO t_click
                 (click_id, click_date, click_id_campaign, click_id_publisher, click_external_id)
             VALUES (:i, :d, :c, :p, :e)'
        )->execute([
            'i' => \App\Core\Ulid::toBinary($ulid),
            'd' => $overrides['click_date'] ?? gmdate('Y-m-d H:i:s.v'),
            'c' => $ctx['campaign_id'],
            'p' => $ctx['publisher_id'],
            'e' => $overrides['click_external_id'] ?? 'EXT-' . substr($ulid, -6),
        ]);

        return $ulid;
    }

    protected function countRows(string $table, string $where = '1', array $args = []): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM $table WHERE $where");
        $stmt->execute($args);

        return (int) $stmt->fetchColumn();
    }
}
