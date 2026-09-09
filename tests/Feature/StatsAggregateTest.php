<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Modules\Stats\Tasks\StatsAggregateTask;
use App\Tests\DatabaseTestCase;

final class StatsAggregateTest extends DatabaseTestCase
{
    private function clic(array $ctx, bool $unique = true, bool $bot = false): string
    {
        $ulid = \App\Core\Ulid::generate();

        $this->pdo->prepare(
            'INSERT INTO t_click
                 (click_id, click_date, click_id_campaign, click_id_publisher,
                  click_is_unique, click_is_bot)
             VALUES (:i, UTC_TIMESTAMP(3), :c, :p, :u, :b)'
        )->execute([
            'i' => \App\Core\Ulid::toBinary($ulid),
            'c' => $ctx['campaign_id'], 'p' => $ctx['publisher_id'],
            'u' => $unique ? 1 : 0, 'b' => $bot ? 1 : 0,
        ]);

        return $ulid;
    }

    private function conversion(array $ctx, string $ulid, string $statut, float $payout): void
    {
        $this->pdo->prepare(
            'INSERT INTO t_conversion
                 (conversion_id_click, conversion_click_date, conversion_id_campaign,
                  conversion_id_publisher, conversion_external_txid, conversion_status, conversion_payout)
             VALUES (:i, UTC_TIMESTAMP(3), :c, :p, :t, :s, :y)'
        )->execute([
            'i' => \App\Core\Ulid::toBinary($ulid),
            'c' => $ctx['campaign_id'], 'p' => $ctx['publisher_id'],
            't' => 'TX-' . substr($ulid, -8), 's' => $statut, 'y' => $payout,
        ]);
    }

    private function stats(array $ctx): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM t_stats_hourly WHERE stats_id_campaign = :c'
        );
        $stmt->execute(['c' => $ctx['campaign_id']]);

        return $stmt->fetch() ?: [];
    }

    public function testComptagesExacts(): void
    {
        $ctx = $this->makeCampaign();

        $a = $this->clic($ctx);
        $b = $this->clic($ctx);
        $this->clic($ctx, true, true);   // bot
        $d = $this->clic($ctx, false);   // repete
        $this->clic($ctx, false);

        $this->conversion($ctx, $a, 'approved', 10);
        $this->conversion($ctx, $b, 'approved', 25);
        $this->conversion($ctx, $d, 'pending', 10);

        (new StatsAggregateTask(sys_get_temp_dir()))->run();
        $s = $this->stats($ctx);

        self::assertSame(5, (int) $s['stats_clicks']);
        self::assertSame(3, (int) $s['stats_clicks_unique']);
        self::assertSame(1, (int) $s['stats_clicks_bot']);
        self::assertSame(2, (int) $s['stats_conversions'], 'seules les validees comptent');
        self::assertSame(1, (int) $s['stats_conversions_pending']);
        self::assertSame('35.0000', $s['stats_payout'], 'le payout d\'une conversion en attente n\'est pas compte');
    }

    /**
     * La task tourne toutes les heures avec rattrapage : elle recalcule des
     * heures deja agregees. Sans REPLACE, les compteurs s'additionneraient a
     * chaque passage.
     */
    public function testLeRecalculNAdditionnePas(): void
    {
        $ctx = $this->makeCampaign();
        $this->clic($ctx);
        $this->clic($ctx);

        $task = new StatsAggregateTask(sys_get_temp_dir());
        $task->run();
        $task->run();
        $task->run();

        self::assertSame(1, $this->countRows('t_stats_hourly', 'stats_id_campaign = :c', ['c' => $ctx['campaign_id']]));
        self::assertSame(2, (int) $this->stats($ctx)['stats_clicks']);
    }

    /**
     * Une conversion arrive souvent bien apres le clic qu'elle solde : son
     * payout doit remonter dans l'heure du CLIC, pas dans celle du postback.
     */
    public function testUneConversionTardiveRemonteDansLHeureDuClic(): void
    {
        $ctx  = $this->makeCampaign();
        $ulid = $this->clic($ctx);

        (new StatsAggregateTask(sys_get_temp_dir()))->run();
        self::assertSame(0, (int) $this->stats($ctx)['stats_conversions']);

        $this->conversion($ctx, $ulid, 'approved', 42);
        (new StatsAggregateTask(sys_get_temp_dir()))->run();

        $s = $this->stats($ctx);
        self::assertSame(1, (int) $s['stats_conversions']);
        self::assertSame('42.0000', $s['stats_payout']);
    }

    public function testAucunClicAucuneLigne(): void
    {
        $ctx = $this->makeCampaign();

        (new StatsAggregateTask(sys_get_temp_dir()))->run();

        self::assertSame(0, $this->countRows('t_stats_hourly', 'stats_id_campaign = :c', ['c' => $ctx['campaign_id']]));
    }
}
