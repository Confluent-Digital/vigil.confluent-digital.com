<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * La file des relais sortants.
 *
 * Aucun appel HTTP n'est fait dans la requete du money site : un timeout de la
 * plateforme externe ferait timeouter le money site, qui retenterait — ou pire,
 * abandonnerait la conversion. On empile ici, et PostbackFlushTask envoie.
 */
final class CreatePostbackQueueTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('t_postback_queue', ['id' => 'pq_id'])
            ->addColumn('pq_id_conversion', 'biginteger', ['signed' => false])
            ->addColumn('pq_id_campaign_postback', 'integer', ['signed' => false])
            // URL finale, macros deja resolues au moment de l'empilement : le
            // worker n'a plus a reconstruire le contexte, et on garde la trace
            // exacte de ce qui a ete demande.
            ->addColumn('pq_url', 'text')
            ->addColumn('pq_method', 'enum', ['values' => ['GET', 'POST'], 'default' => 'GET'])
            ->addColumn('pq_body', 'text', ['null' => true])
            ->addColumn('pq_headers', 'text', ['null' => true])
            ->addColumn('pq_status', 'enum', [
                'values' => ['pending', 'sent', 'failed', 'abandoned'],
                'default' => 'pending',
            ])
            ->addColumn('pq_attempts', 'integer', ['limit' => 4, 'default' => 0])
            ->addColumn('pq_next_try_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            // Conserves a CHAQUE tentative, succes comme echec : c'est la seule
            // preuve utilisable le jour ou le client conteste.
            ->addColumn('pq_http_code', 'integer', ['limit' => 5, 'null' => true])
            ->addColumn('pq_response', 'text', ['null' => true])
            ->addColumn('pq_error', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('pq_date_create', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('pq_date_sent', 'datetime', ['null' => true])
            ->addIndex(['pq_status', 'pq_next_try_at'])
            ->addIndex(['pq_id_conversion'])
            ->create();
    }
}
