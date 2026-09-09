<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * La contrainte UNIQUE (campagne, txid) est la protection la plus importante du
 * systeme. Un money site qui retente son postback — timeout, cron de
 * rattrapage, double clic d'un operateur — creerait sinon deux conversions,
 * relayees deux fois chez le partenaire. Ces doublons-la ne se rattrapent pas.
 *
 * Ne JAMAIS deployer une migration qui la retire, meme temporairement.
 */
final class CreateConversionTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('t_conversion', ['id' => 'conversion_id'])
            ->addColumn('conversion_id_click', 'binary', ['limit' => 16, 'null' => true])
            // Copie de la date du clic : permet de le retrouver dans sa
            // partition sans avoir a decoder l'ULID en SQL.
            ->addColumn('conversion_click_date', 'datetime', ['precision' => 3, 'null' => true])
            ->addColumn('conversion_id_campaign', 'integer', ['signed' => false])
            ->addColumn('conversion_id_publisher', 'integer', ['signed' => false, 'null' => true])
            // Identifiant de transaction du money site. Vide = on retombe sur
            // le clic comme cle d'idempotence (une conversion par clic), ce qui
            // est degrade : cela interdit deux ventes sur un meme clic.
            ->addColumn('conversion_external_txid', 'string', ['limit' => 128])
            ->addColumn('conversion_status', 'enum', [
                'values' => ['pending', 'approved', 'rejected', 'chargeback'],
                'default' => 'approved',
            ])
            ->addColumn('conversion_payout', 'decimal', [
                'precision' => 12, 'scale' => 4, 'default' => 0,
            ])
            ->addColumn('conversion_revenue', 'decimal', [
                'precision' => 12, 'scale' => 4, 'default' => 0,
            ])
            ->addColumn('conversion_currency', 'string', ['limit' => 3, 'default' => 'EUR'])
            ->addColumn('conversion_ip', 'binary', ['limit' => 16, 'null' => true])
            ->addColumn('conversion_raw_query', 'json', ['null' => true])
            ->addColumn('conversion_date', 'datetime', [
                'precision' => 3, 'default' => 'CURRENT_TIMESTAMP',
            ])
            ->addColumn('conversion_date_update', 'datetime', ['precision' => 3, 'null' => true])
            ->addIndex(['conversion_id_campaign', 'conversion_external_txid'], ['unique' => true])
            ->addIndex(['conversion_id_click'])
            ->addIndex(['conversion_date'])
            ->addIndex(['conversion_status'])
            ->addIndex(['conversion_id_publisher'])
            ->create();
    }
}
