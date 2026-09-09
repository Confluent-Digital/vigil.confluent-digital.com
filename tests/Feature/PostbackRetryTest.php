<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Modules\Postback\Tasks\PostbackFlushTask;
use App\Tests\DatabaseTestCase;

/**
 * Le worker de relais. Le client HTTP est injecte : aucun appel reel ne doit
 * partir depuis une suite de tests — il polluerait le reporting d'un partenaire
 * et ne se retirerait pas.
 */
final class PostbackRetryTest extends DatabaseTestCase
{
    private function enfile(string $url = 'https://ext.test/pb'): int
    {
        $this->pdo->prepare(
            'INSERT INTO t_postback_queue
                 (pq_id_conversion, pq_id_campaign_postback, pq_url, pq_method, pq_next_try_at)
             VALUES (1, 1, :u, \'GET\', UTC_TIMESTAMP())'
        )->execute(['u' => $url]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array{ok: bool, code: int|null, body: string, error: string|null} $reponse */
    private function worker(array $reponse): PostbackFlushTask
    {
        return new PostbackFlushTask(
            sys_get_temp_dir(),
            static fn (array $row): array => $reponse
        );
    }

    private function ligne(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM t_postback_queue WHERE pq_id = :i');
        $stmt->execute(['i' => $id]);

        return $stmt->fetch();
    }

    public function testEnvoiReussi(): void
    {
        $id = $this->enfile();

        $this->worker(['ok' => true, 'code' => 200, 'body' => 'OK', 'error' => null])->send();

        $ligne = $this->ligne($id);
        self::assertSame('sent', $ligne['pq_status']);
        self::assertSame(1, (int) $ligne['pq_attempts']);
        self::assertSame(200, (int) $ligne['pq_http_code']);
        self::assertNotNull($ligne['pq_date_sent']);
    }

    /**
     * Le bareme documente est 1 / 5 / 15 / 60 / 360 minutes. Un decalage
     * d'indice avait fait partir le premier reessai a 5 minutes, et la premiere
     * marche ne servait jamais.
     */
    public function testBaremeDeReessai(): void
    {
        $id     = $this->enfile();
        $worker = $this->worker(['ok' => false, 'code' => 500, 'body' => '', 'error' => 'boom']);
        $attendu = [1, 5, 15, 60];

        foreach ($attendu as $rang => $minutes) {
            $this->pdo->prepare('UPDATE t_postback_queue SET pq_next_try_at = UTC_TIMESTAMP() WHERE pq_id = :i')
                ->execute(['i' => $id]);
            $worker->send();

            $ligne = $this->ligne($id);
            self::assertSame($rang + 1, (int) $ligne['pq_attempts']);
            self::assertSame('failed', $ligne['pq_status']);

            $delai = (int) $this->pdo->query(
                "SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), '{$ligne['pq_next_try_at']}')"
            )->fetchColumn();
            self::assertSame($minutes, $delai + 1 >= $minutes ? $minutes : $delai,
                "tentative " . ($rang + 1) . " : reessai attendu dans $minutes min");
        }
    }

    public function testAbandonALaCinquiemeTentative(): void
    {
        $id     = $this->enfile();
        $worker = $this->worker(['ok' => false, 'code' => 500, 'body' => '', 'error' => 'boom']);

        for ($i = 0; $i < 5; $i++) {
            $this->pdo->prepare('UPDATE t_postback_queue SET pq_next_try_at = UTC_TIMESTAMP() WHERE pq_id = :i')
                ->execute(['i' => $id]);
            $worker->send();
        }

        $ligne = $this->ligne($id);
        self::assertSame('abandoned', $ligne['pq_status']);
        self::assertSame(5, (int) $ligne['pq_attempts']);
    }

    /** La reponse est conservee a chaque tentative : c'est la preuve en cas de litige. */
    public function testLaReponseEstConserveeMemeEnEchec(): void
    {
        $id = $this->enfile();

        $this->worker(['ok' => false, 'code' => 403, 'body' => 'Forbidden', 'error' => null])->send();

        $ligne = $this->ligne($id);
        self::assertSame(403, (int) $ligne['pq_http_code']);
        self::assertSame('Forbidden', $ligne['pq_response']);
    }

    public function testUnRelaiDontLHeureNEstPasVenueNEstPasPris(): void
    {
        $id = $this->enfile();
        $this->pdo->prepare(
            'UPDATE t_postback_queue SET pq_next_try_at = UTC_TIMESTAMP() + INTERVAL 1 HOUR WHERE pq_id = :i'
        )->execute(['i' => $id]);

        self::assertSame(0, $this->worker(['ok' => true, 'code' => 200, 'body' => 'OK', 'error' => null])->send());
        self::assertSame('pending', $this->ligne($id)['pq_status']);
    }
}
