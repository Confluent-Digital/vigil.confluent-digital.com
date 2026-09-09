<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * L'acces d'un publisher a une campagne, et le token du lien qui va avec.
 *
 * Le token porte le COUPLE campagne x publisher, et non l'un puis l'autre en
 * deux parametres : un publisher ne peut donc pas fabriquer un lien vers une
 * campagne a laquelle il n'a pas acces, puisqu'il n'en detient pas le token.
 * Et le chemin chaud n'a qu'une seule cle a resoudre par clic.
 */
final class CreateCampaignPublisherTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('t_campaign_publisher', ['id' => 'cp_id'])
            ->addColumn('cp_id_campaign', 'integer', ['signed' => false])
            ->addColumn('cp_id_publisher', 'integer', ['signed' => false])
            ->addColumn('cp_token', 'string', ['limit' => 24])
            // NULL = on prend le payout de la campagne.
            ->addColumn('cp_payout', 'decimal', [
                'precision' => 12, 'scale' => 4, 'null' => true,
            ])
            ->addColumn('cp_status', 'enum', [
                'values' => ['active', 'paused'], 'default' => 'active',
            ])
            ->addColumn('cp_date_create', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['cp_token'], ['unique' => true])
            ->addIndex(['cp_id_campaign', 'cp_id_publisher'], ['unique' => true])
            ->addIndex(['cp_id_publisher'])
            ->create();
    }
}
