<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Les N destinations de relai d'une campagne.
 *
 * C'est la reponse au probleme d'origine : le money site ne configure qu'un
 * seul postback, vers Vigil, et c'est cette table qui demultiplexe vers la
 * plateforme externe, un second tracker, un pixel, un webhook.
 */
final class CreateCampaignPostbackTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('t_campaign_postback', ['id' => 'cpb_id'])
            ->addColumn('cpb_id_campaign', 'integer', ['signed' => false])
            // NULL = toutes les sources ; renseigne = relai cible sur un publisher.
            ->addColumn('cpb_id_publisher', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('cpb_name', 'string', ['limit' => 120])
            ->addColumn('cpb_url', 'text')
            ->addColumn('cpb_method', 'enum', ['values' => ['GET', 'POST'], 'default' => 'GET'])
            ->addColumn('cpb_body', 'text', ['null' => true])
            ->addColumn('cpb_headers', 'text', ['null' => true])
            // CSV des statuts de conversion qui declenchent ce relai.
            ->addColumn('cpb_on_status', 'string', ['limit' => 60, 'default' => 'approved'])
            // Vide = seul le code HTTP compte. Certaines plateformes repondent
            // 200 avec {"status":"error"} : un 2xx ne prouve rien chez elles.
            ->addColumn('cpb_success_pattern', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('cpb_status', 'enum', [
                'values' => ['active', 'paused'], 'default' => 'active',
            ])
            ->addColumn('cpb_date_create', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['cpb_id_campaign', 'cpb_status'])
            ->addIndex(['cpb_id_publisher'])
            ->create();
    }
}
