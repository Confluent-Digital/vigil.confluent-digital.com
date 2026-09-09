<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Core\Database;
use App\Modules\Maintenance\Tasks\PartitionMaintenanceTask;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Une partition manquante ne degrade pas : elle fait echouer l'INSERT. C'est
 * une panne d'ingestion TOTALE et silencieuse, qui se declenche au premier clic
 * du mois.
 *
 * Ce test n'herite PAS de DatabaseTestCase : `ALTER TABLE` provoque un commit
 * implicite en MySQL/MariaDB, une transaction annulee ne protegerait donc rien.
 * On travaille sur une table jetable, creee et supprimee par le test.
 */
final class PartitionMaintenanceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = Database::get();

        $nom = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!str_ends_with($nom, '_test')) {
            self::fail("La suite doit tourner sur une base *_test, pas sur « $nom ».");
        }
    }

    /** @return string[] */
    private function partitions(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT partition_name FROM information_schema.partitions
              WHERE table_schema = DATABASE() AND table_name = 't_click'
                AND partition_name IS NOT NULL"
        );
        $stmt->execute();

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testLesTroisProchainsMoisSontCouverts(): void
    {
        (new PartitionMaintenanceTask(sys_get_temp_dir()))->ensure(3);

        $partitions = $this->partitions();
        $mois       = new \DateTimeImmutable('first day of this month', new \DateTimeZone('UTC'));

        for ($i = 0; $i <= 3; $i++) {
            $attendue = 'p' . $mois->modify("+$i month")->format('Ym');
            self::assertContains($attendue, $partitions, "la partition $attendue doit exister");
        }

        self::assertContains('pmax', $partitions, 'la partition fourre-tout reste en fin');
    }

    public function testLaTacheEstIdempotente(): void
    {
        $task = new PartitionMaintenanceTask(sys_get_temp_dir());

        $task->ensure(3);
        $avant = $this->partitions();

        self::assertSame(0, $task->ensure(3), 'rien a creer au second passage');
        self::assertSame($avant, $this->partitions());
    }

    /**
     * La preuve par l'usage : un clic date du mois prochain doit pouvoir
     * s'inserer. Sans partition, MariaDB rejette la ligne.
     */
    public function testUnClicDuMoisProchainSInsere(): void
    {
        (new PartitionMaintenanceTask(sys_get_temp_dir()))->ensure(3);

        $ulid = \App\Core\Ulid::generate();
        $date = (new \DateTimeImmutable('first day of next month', new \DateTimeZone('UTC')))
            ->format('Y-m-d H:i:s.v');

        $this->pdo->prepare(
            'INSERT INTO t_click (click_id, click_date, click_id_campaign, click_id_publisher)
             VALUES (:i, :d, 999999, 999999)'
        )->execute(['i' => \App\Core\Ulid::toBinary($ulid), 'd' => $date]);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM t_click WHERE click_id = :i AND click_date = :d');
        $stmt->execute(['i' => \App\Core\Ulid::toBinary($ulid), 'd' => $date]);
        self::assertSame(1, (int) $stmt->fetchColumn());

        $this->pdo->prepare('DELETE FROM t_click WHERE click_id_campaign = 999999')->execute();
    }
}
