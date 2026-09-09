<?php

declare(strict_types=1);

namespace App\Modules\Stats\Tasks;

use App\Core\Database;

/**
 * Alimente t_stats_hourly. Cron horaire.
 *
 * Le back-office ne lit jamais t_click : c'est ce qui garde les ecrans rapides
 * quand la table des clics atteint la centaine de millions de lignes.
 */
final class StatsAggregateTask
{
    /**
     * Nombre d'heures recalculees a chaque passage. Il ne suffit pas de traiter
     * l'heure ecoulee : une conversion arrive souvent bien apres le clic
     * qu'elle solde, et son payout doit remonter dans l'heure du clic.
     */
    private const CATCHUP_HOURS = 3;

    public function __construct(private readonly string $logsDir)
    {
    }

    public function run(int $hours = self::CATCHUP_HOURS): int
    {
        $pdo   = Database::get();
        $lines = 0;

        // REPLACE plutot qu'INSERT : recalculer une heure deja agregee doit
        // ecraser le resultat precedent, pas s'y ajouter.
        $clicks = <<<'SQL'
            REPLACE INTO t_stats_hourly
                (stats_date_hour, stats_id_campaign, stats_id_publisher,
                 stats_clicks, stats_clicks_unique, stats_clicks_bot,
                 stats_conversions, stats_conversions_pending, stats_payout, stats_revenue)
            SELECT h.hour, h.campaign, h.publisher,
                   h.clicks, h.clicks_unique, h.clicks_bot,
                   COALESCE(c.conversions, 0), COALESCE(c.pending, 0),
                   COALESCE(c.payout, 0), COALESCE(c.revenue, 0)
              FROM (
                    SELECT DATE_FORMAT(click_date, '%Y-%m-%d %H:00:00') AS hour,
                           click_id_campaign  AS campaign,
                           click_id_publisher AS publisher,
                           COUNT(*)                        AS clicks,
                           SUM(click_is_unique = 1)        AS clicks_unique,
                           SUM(click_is_bot = 1)           AS clicks_bot
                      FROM t_click
                     WHERE click_date >= :from AND click_date < :to
                  GROUP BY hour, campaign, publisher
                   ) h
         LEFT JOIN (
                    SELECT DATE_FORMAT(conversion_click_date, '%Y-%m-%d %H:00:00') AS hour,
                           conversion_id_campaign  AS campaign,
                           conversion_id_publisher AS publisher,
                           SUM(conversion_status = 'approved')            AS conversions,
                           SUM(conversion_status = 'pending')             AS pending,
                           SUM(IF(conversion_status = 'approved', conversion_payout, 0))  AS payout,
                           SUM(IF(conversion_status = 'approved', conversion_revenue, 0)) AS revenue
                      FROM t_conversion
                     WHERE conversion_click_date >= :from2 AND conversion_click_date < :to2
                  GROUP BY hour, campaign, publisher
                   ) c
                ON c.hour = h.hour AND c.campaign = h.campaign
               AND (c.publisher = h.publisher OR (c.publisher IS NULL AND h.publisher IS NULL))
        SQL;

        // Bornes calculees en PHP : `t_click` est partitionnee sur click_date,
        // et une borne litterale permet a MariaDB d'elaguer les partitions.
        $to   = new \DateTimeImmutable(gmdate('Y-m-d H:00:00') . ' +1 hour', new \DateTimeZone('UTC'));
        $from = $to->modify(sprintf('-%d hours', $hours + 1));

        $stmt = $pdo->prepare($clicks);
        $stmt->execute([
            'from'  => $from->format('Y-m-d H:i:s'),
            'to'    => $to->format('Y-m-d H:i:s'),
            'from2' => $from->format('Y-m-d H:i:s'),
            'to2'   => $to->format('Y-m-d H:i:s'),
        ]);
        $lines = $stmt->rowCount();

        $this->log(sprintf(
            '%s -> %s : %d ligne(s) agregee(s)',
            $from->format('Y-m-d H:i'),
            $to->format('Y-m-d H:i'),
            $lines
        ));

        return $lines;
    }

    private function log(string $line): void
    {
        $dir = $this->logsDir . '/tasks/stats/aggregate';
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
