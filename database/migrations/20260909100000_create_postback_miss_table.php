<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Journal des postbacks refuses.
 *
 * `/pb` rend TOUJOURS 200, meme quand il refuse — c'est voulu : un 404 ou un
 * 403 declencherait chez le money site des files de retry pour une conversion
 * qui ne sera de toute facon jamais rattachable, et lui dire « secret
 * invalide » l'aiderait a le deviner.
 *
 * Mais cette discretion a un revers : celui qui branche une campagne voit
 * « HTTP 200 » et croit que ca marche, alors qu'aucune conversion n'est creee.
 * L'echec est parfaitement silencieux — exactement le genre de chose qui coute
 * une semaine avant qu'on s'en apercoive.
 *
 * Agrege par (jour, raison, campagne), comme `t_link_miss` et pour la meme
 * raison : sans borne, un money site en boucle ferait de cette table un levier
 * d'amplification.
 */
final class CreatePostbackMissTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('t_postback_miss', ['id' => 'pmiss_id'])
            ->addColumn('pmiss_date', 'date')
            ->addColumn('pmiss_reason', 'enum', ['values' => [
                'clickid_absent',
                'clickid_malforme',
                'clickid_inconnu',
                'campagne_introuvable',
                'secret_invalide',
                'ip_refusee',
                'erreur_interne',
            ]])
            // Renseignee quand on a pu remonter jusqu'a elle : c'est le cas
            // reparable, et celui ou l'on sait quoi corriger.
            ->addColumn('pmiss_id_campaign', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('pmiss_count', 'integer', ['signed' => false, 'default' => 1])
            ->addColumn('pmiss_first_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('pmiss_last_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('pmiss_last_ip', 'varbinary', ['limit' => 16, 'null' => true])
            // La derniere requete recue, telle quelle : c'est elle qui permet
            // de dire « il manque &s= » au lieu de « ca ne marche pas ».
            ->addColumn('pmiss_last_query', 'string', ['limit' => 1000, 'null' => true])
            ->addColumn('pmiss_last_detail', 'string', ['limit' => 255, 'null' => true])
            ->addIndex(['pmiss_date', 'pmiss_reason', 'pmiss_id_campaign'], ['unique' => true])
            ->addIndex(['pmiss_date', 'pmiss_count'])
            ->create();
    }
}
