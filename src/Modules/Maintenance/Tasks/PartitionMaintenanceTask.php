<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Tasks;

use App\Core\Database;

/**
 * Cree les partitions mensuelles de t_click a l'avance. Cron quotidien.
 *
 * Une partition manquante ne degrade pas : elle fait echouer l'INSERT. C'est
 * une panne d'ingestion TOTALE et silencieuse, qui se declenche au premier clic
 * du mois. D'ou trois mois d'avance et une alerte si le compte descend.
 */
final class PartitionMaintenanceTask
{
    private const TABLE  = 't_click';
    private const COLUMN = 'click_date';
    private const AHEAD  = 3;

    public function __construct(private readonly string $logsDir)
    {
    }

    public function ensure(int $ahead = self::AHEAD): int
    {
        $pdo      = Database::get();
        $existing = $this->existingPartitions($pdo);

        if ($existing === []) {
            $this->log('ALERTE : ' . self::TABLE . " n'est pas partitionnee.");
            return 0;
        }

        $created = 0;
        $month   = new \DateTimeImmutable('first day of this month', new \DateTimeZone('UTC'));

        for ($i = 0; $i <= $ahead; $i++) {
            $current = $month->modify(sprintf('+%d month', $i));
            $name    = 'p' . $current->format('Ym');

            if (in_array($name, $existing, true)) {
                continue;
            }

            // On ne peut pas ajouter une partition apres MAXVALUE : il faut
            // decouper `pmax`. REORGANIZE le fait en une operation, sans
            // deplacer les lignes des autres partitions.
            $bound = $current->modify('+1 month')->format('Y-m-d');
            $pdo->exec(sprintf(
                "ALTER TABLE %s REORGANIZE PARTITION pmax INTO (
                     PARTITION %s VALUES LESS THAN ('%s'),
                     PARTITION pmax VALUES LESS THAN (MAXVALUE))",
                self::TABLE,
                $name,
                $bound
            ));

            $this->log("partition $name creee (< $bound)");
            $created++;
        }

        // Compte des mois couverts au-dela du mois courant : si l'on tombe a
        // zero, l'ingestion s'arretera au changement de mois.
        $future = 0;
        foreach ($this->existingPartitions($pdo) as $name) {
            if ($name !== 'pmax' && $name > 'p' . gmdate('Ym')) {
                $future++;
            }
        }
        if ($future < 1) {
            $this->log('ALERTE : aucune partition future — l\'ingestion cessera au changement de mois.');
        }

        $this->log(sprintf('%d partition(s) creee(s), %d mois d\'avance', $created, $future));

        return $created;
    }

    /** @return string[] */
    private function existingPartitions(\PDO $pdo): array
    {
        $stmt = $pdo->prepare(
            'SELECT partition_name FROM information_schema.partitions
              WHERE table_schema = DATABASE() AND table_name = :t AND partition_name IS NOT NULL'
        );
        $stmt->execute(['t' => self::TABLE]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function log(string $line): void
    {
        $dir = $this->logsDir . '/tasks/maintenance/partition';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents(
            $dir . '/' . gmdate('Ymd') . '.log',
            gmdate('Y-m-d H:i:s') . ' ' . $line . PHP_EOL,
            FILE_APPEND
        );
    }
}
