<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * La seule table qui grossit vite.
 *
 * Partitionnee par mois. MariaDB impose que la cle de partition fasse partie de
 * toute cle unique, d'ou la PK composite (click_id, click_date). Consequence :
 * chercher un clic par son seul click_id scannerait TOUTES les partitions. On
 * ne le fait jamais — les 48 premiers bits de l'ULID portent l'horodatage, donc
 * `pb.php` decode la date depuis le clickid et borne `click_date`.
 *
 * Aucune contrainte de cle etrangere : une table partitionnee n'en accepte pas,
 * et chaque index est un cout a chaque ecriture.
 */
final class CreateClickTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('t_click', [
                'id' => false,
                'primary_key' => ['click_id', 'click_date'],
                'engine' => 'InnoDB',
            ])
            ->addColumn('click_id', 'binary', ['limit' => 16, 'comment' => 'ULID'])
            ->addColumn('click_date', 'datetime', ['precision' => 3])
            ->addColumn('click_id_campaign', 'integer', ['signed' => false])
            ->addColumn('click_id_publisher', 'integer', ['signed' => false])
            // INET6_ATON : 16 octets au lieu des 45 d'un varchar, sur la table
            // la plus grosse du systeme — et les comparaisons de plages restent
            // possibles.
            ->addColumn('click_ip', 'binary', ['limit' => 16, 'null' => true])
            ->addColumn('click_country', 'char', ['limit' => 2, 'null' => true])
            ->addColumn('click_user_agent', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('click_referer', 'string', ['limit' => 500, 'null' => true])
            // Le click id de la plateforme externe : c'est lui qu'on lui
            // renverra au moment du relai.
            ->addColumn('click_external_id', 'string', ['limit' => 128, 'null' => true])
            ->addColumn('click_sub1', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('click_sub2', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('click_sub3', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('click_sub4', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('click_sub5', 'string', ['limit' => 255, 'null' => true])
            // Tout le reste de la query string. Ne JAMAIS ajouter une colonne
            // pour un nouveau parametre partenaire : elle se paierait a chaque
            // ecriture. Une colonne ne se justifie que si l'on filtre dessus.
            ->addColumn('click_raw_query', 'json', ['null' => true])
            ->addColumn('click_is_unique', 'boolean', ['default' => 1])
            // Un bot est enregistre et redirige normalement, jamais bloque :
            // bloquer casserait les crawlers de Facebook et Google Ads qui
            // verifient les URLs de destination avant de valider une annonce.
            ->addColumn('click_is_bot', 'boolean', ['default' => 0])
            ->addIndex(['click_date', 'click_id_campaign'])
            ->addIndex(['click_date', 'click_id_publisher'])
            ->addIndex(['click_external_id'])
            ->create();

        // Phinx ne sait pas declarer de partitions : on passe en SQL brut.
        // Les partitions suivantes ne sont PAS des migrations, c'est
        // PartitionMaintenanceTask qui les cree trois mois a l'avance. Une
        // partition manquante arrete l'ingestion en silence.
        $start = new DateTimeImmutable('first day of this month', new DateTimeZone('UTC'));
        $parts = [];
        for ($i = 0; $i < 3; $i++) {
            $bound = $start->modify(sprintf('+%d month', $i + 1));
            $parts[] = sprintf(
                "PARTITION p%s VALUES LESS THAN ('%s')",
                $start->modify(sprintf('+%d month', $i))->format('Ym'),
                $bound->format('Y-m-d')
            );
        }
        $parts[] = 'PARTITION pmax VALUES LESS THAN (MAXVALUE)';

        $this->execute(
            'ALTER TABLE t_click PARTITION BY RANGE COLUMNS(click_date) ('
            . implode(', ', $parts) . ')'
        );
    }

    public function down(): void
    {
        $this->table('t_click')->drop()->save();
    }
}
