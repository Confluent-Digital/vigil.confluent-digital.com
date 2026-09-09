<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateCampaignTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('t_campaign', ['id' => 'campaign_id'])
            ->addColumn('campaign_id_client', 'integer', ['signed' => false])
            ->addColumn('campaign_name', 'string', ['limit' => 200])
            ->addColumn('campaign_status', 'enum', [
                'values' => ['active', 'paused', 'archived'], 'default' => 'paused',
            ])
            // Template a macros. La destination vient UNIQUEMENT d'ici : si un
            // parametre de la requete pouvait influencer l'hote, /c/ deviendrait
            // un redirecteur ouvert exploitable pour du phishing.
            ->addColumn('campaign_dest_url', 'text')
            ->addColumn('campaign_payout', 'decimal', [
                'precision' => 12, 'scale' => 4, 'default' => 0,
            ])
            ->addColumn('campaign_payout_type', 'enum', [
                'values' => ['fixed', 'percent', 'from_postback'], 'default' => 'fixed',
            ])
            ->addColumn('campaign_currency', 'string', ['limit' => 3, 'default' => 'EUR'])
            // Trois niveaux d'authentification du postback entrant, cumulables.
            // Une campagne sans aucun des trois accepte n'importe quelle
            // conversion forgee : le back-office doit l'afficher en alerte.
            ->addColumn('campaign_postback_secret', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('campaign_postback_ips', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('campaign_postback_response', 'string', [
                'limit' => 255, 'default' => 'OK',
                'comment' => 'Corps exact attendu par le money site en reponse au postback',
            ])
            ->addColumn('campaign_date_stop', 'date', ['null' => true])
            ->addColumn('campaign_date_create', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('campaign_date_update', 'datetime', ['null' => true])
            ->addIndex(['campaign_id_client'])
            ->addIndex(['campaign_status'])
            ->addIndex(['campaign_name'])
            ->create();
    }
}
