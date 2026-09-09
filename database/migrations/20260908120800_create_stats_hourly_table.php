<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Pre-agregat lu par le back-office.
 *
 * Le BO ne lit JAMAIS t_click ni t_conversion pour afficher des stats : c'est
 * ce qui garde les ecrans rapides quand t_click depasse la centaine de millions
 * de lignes.
 */
final class CreateStatsHourlyTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('t_stats_hourly', ['id' => 'stats_id'])
            // Tronquee a l'heure, en UTC comme tout le reste.
            ->addColumn('stats_date_hour', 'datetime')
            ->addColumn('stats_id_campaign', 'integer', ['signed' => false])
            ->addColumn('stats_id_publisher', 'integer', ['signed' => false])
            ->addColumn('stats_clicks', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('stats_clicks_unique', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('stats_clicks_bot', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('stats_conversions', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('stats_conversions_pending', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('stats_payout', 'decimal', [
                'precision' => 14, 'scale' => 4, 'default' => 0,
            ])
            ->addColumn('stats_revenue', 'decimal', [
                'precision' => 14, 'scale' => 4, 'default' => 0,
            ])
            ->addColumn('stats_date_update', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(
                ['stats_date_hour', 'stats_id_campaign', 'stats_id_publisher'],
                ['unique' => true]
            )
            ->addIndex(['stats_id_campaign'])
            ->addIndex(['stats_id_publisher'])
            ->create();
    }
}
