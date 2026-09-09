<?php

declare(strict_types=1);

namespace App\Modules\Postback\Tasks;

use App\Core\Database;
use PDO;

/**
 * Vide t_postback_queue. Cron chaque minute.
 *
 * C'est le seul endroit de Vigil qui fait un appel HTTP sortant : ni `/c` ni
 * `/pb` n'en font, pour qu'une plateforme externe lente ne puisse jamais faire
 * timeouter un visiteur ou un money site.
 */
final class PostbackFlushTask
{
    /** Minutes avant la prochaine tentative, par numero d'essai. */
    private const BACKOFF = [1, 5, 15, 60, 360];

    private const BATCH = 200;
    private const CONNECT_TIMEOUT = 10;
    private const TOTAL_TIMEOUT = 15;

    public function __construct(
        private readonly string $logsDir,
        private readonly ?\Closure $httpClient = null,
    ) {
    }

    public function send(): int
    {
        $pdo  = Database::get();
        $rows = $this->claim($pdo);
        $done = 0;

        foreach ($rows as $row) {
            $result = $this->call($row);
            $this->record($pdo, $row, $result);
            $done++;
        }

        $this->log(sprintf('%d relai(s) traite(s)', $done));

        return $done;
    }

    /**
     * SKIP LOCKED : deux workers lances en parallele — un cron qui deborde sur
     * le suivant — ne se disputent pas les memes lignes.
     *
     * @return array<int, array<string, mixed>>
     */
    private function claim(PDO $pdo): array
    {
        // PDO ne sait pas imbriquer les transactions : il leve « There is
        // already an active transaction ». On n'ouvre donc la notre que si
        // l'appelant n'en tient pas deja une — c'est le cas d'un test qui
        // englobe la task pour l'annuler, et l'atomicite reste assuree par la
        // transaction externe.
        $transactionAMoi = !$pdo->inTransaction();

        if ($transactionAMoi) {
            $pdo->beginTransaction();
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT q.*, pb.cpb_success_pattern
                   FROM t_postback_queue q
              LEFT JOIN t_campaign_postback pb ON pb.cpb_id = q.pq_id_campaign_postback
                  WHERE q.pq_status IN ('pending', 'failed')
                    AND q.pq_next_try_at <= UTC_TIMESTAMP()
                  ORDER BY q.pq_next_try_at
                  LIMIT " . self::BATCH . "
                  FOR UPDATE SKIP LOCKED"
            );
            $stmt->execute();
            $rows = $stmt->fetchAll();

            if ($rows !== []) {
                $ids = array_column($rows, 'pq_id');
                $pdo->prepare(
                    'UPDATE t_postback_queue
                        SET pq_next_try_at = UTC_TIMESTAMP() + INTERVAL 10 MINUTE
                      WHERE pq_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
                )->execute($ids);
            }

            if ($transactionAMoi) {
                $pdo->commit();
            }

            return $rows;
        } catch (\Throwable $e) {
            if ($transactionAMoi && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed> $row
     * @return array{ok: bool, code: int|null, body: string, error: string|null}
     */
    private function call(array $row): array
    {
        if ($this->httpClient !== null) {
            // Injecte en test : aucun relai reel ne doit partir depuis une
            // suite de tests, il polluerait le reporting d'un partenaire.
            return ($this->httpClient)($row);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $row['pq_url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TOTAL_TIMEOUT,
            // Pas de redirection suivie : une plateforme compromise pourrait
            // rediriger vers une adresse interne et faire de ce worker un
            // relai SSRF.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'Vigil/1.0 (+postback)',
        ]);

        if ($row['pq_method'] === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) ($row['pq_body'] ?? ''));
        }
        if (!empty($row['pq_headers'])) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, array_filter(
                array_map('trim', explode("\n", (string) $row['pq_headers']))
            ));
        }

        $body  = curl_exec($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($ch) !== 0 ? curl_error($ch) : null;
        curl_close($ch);

        $body = is_string($body) ? substr($body, 0, 2000) : '';

        return [
            'ok'    => $this->isSuccess($code, $body, $row['cpb_success_pattern'] ?? null),
            'code'  => $code ?: null,
            'body'  => $body,
            'error' => $error,
        ];
    }

    /**
     * Un 2xx ne suffit pas partout : certaines plateformes repondent 200 avec
     * {"status":"error"}. Une destination peut donc declarer un motif de succes.
     */
    private function isSuccess(int $code, string $body, ?string $pattern): bool
    {
        if ($code < 200 || $code >= 300) {
            return false;
        }
        if ($pattern === null || trim($pattern) === '') {
            return true;
        }

        $match = @preg_match('~' . str_replace('~', '\~', $pattern) . '~i', $body);

        // Motif invalide : on ne fait pas echouer un relai a cause d'une regex
        // mal saisie dans le back-office, mais on le trace.
        if ($match === false) {
            $this->log('motif de succes invalide sur la destination : ' . $pattern);
            return true;
        }

        return $match === 1;
    }

    /**
     * @param array<string, mixed> $row
     * @param array{ok: bool, code: int|null, body: string, error: string|null} $result
     */
    private function record(PDO $pdo, array $row, array $result): void
    {
        $attempts = (int) $row['pq_attempts'] + 1;

        if ($result['ok']) {
            $pdo->prepare(
                "UPDATE t_postback_queue
                    SET pq_status = 'sent', pq_attempts = :n, pq_http_code = :code,
                        pq_response = :body, pq_error = NULL, pq_date_sent = UTC_TIMESTAMP()
                  WHERE pq_id = :id"
            )->execute([
                'n' => $attempts, 'code' => $result['code'],
                'body' => $result['body'], 'id' => $row['pq_id'],
            ]);

            return;
        }

        // $attempts vient d'etre incremente : la 1re tentative echouee doit
        // piocher BACKOFF[0]. Sans le -1, le premier reessai partait a 5 min
        // au lieu d'une, et la premiere entree du bareme ne servait jamais.
        $exhausted = $attempts >= count(self::BACKOFF);
        $delay     = self::BACKOFF[min($attempts - 1, count(self::BACKOFF) - 1)];

        $pdo->prepare(
            "UPDATE t_postback_queue
                SET pq_status = :status, pq_attempts = :n, pq_http_code = :code,
                    pq_response = :body, pq_error = :error,
                    pq_next_try_at = UTC_TIMESTAMP() + INTERVAL :delay MINUTE
              WHERE pq_id = :id"
        )->execute([
            'status' => $exhausted ? 'abandoned' : 'failed',
            'n'      => $attempts,
            'code'   => $result['code'],
            'body'   => $result['body'],
            'error'  => $result['error'] !== null ? substr($result['error'], 0, 255) : null,
            'delay'  => $delay,
            'id'     => $row['pq_id'],
        ]);

        $this->log(sprintf(
            'relai %d echec (tentative %d, http=%s) : %s',
            $row['pq_id'],
            $attempts,
            $result['code'] ?? '-',
            $result['error'] ?? substr($result['body'], 0, 120)
        ));
    }

    private function log(string $line): void
    {
        $dir = $this->logsDir . '/tasks/postback/flush';
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
