<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * `pmiss_id_campaign` passe de NULL a 0 pour « campagne inconnue ».
 *
 * Dans un index UNIQUE, MySQL et MariaDB considerent chaque NULL comme
 * DISTINCT : `UNIQUE (date, raison, campagne)` n'empechait donc rien des que la
 * campagne etait inconnue. Chaque appel creait une ligne — et ce sont
 * justement les cas sans campagne (clickid absent, malforme, inconnu) qu'un
 * scanner peut inonder. L'agregation, qui devait borner la table, ne
 * s'appliquait pas la ou elle etait le plus necessaire.
 *
 * 0 est une valeur comme une autre pour l'index, et aucune campagne ne porte
 * cet identifiant : la jointure d'affichage ne trouve rien et rend « — »,
 * exactement comme avec NULL.
 *
 * Detecte par `PostbackMissTest::testLesRefusSontAgreges` : 15 appels
 * produisaient 15 lignes au lieu d'une.
 */
final class FixPostbackMissUniqueNull extends AbstractMigration
{
    public function up(): void
    {
        // Les lignes existantes d'abord : la colonne devient NOT NULL ensuite.
        $this->execute('UPDATE t_postback_miss SET pmiss_id_campaign = 0 WHERE pmiss_id_campaign IS NULL');

        // Les doublons crees par le defaut sont fusionnes, sinon l'index
        // unique refuserait de se poser.
        $this->execute(
            'CREATE TEMPORARY TABLE tmp_pmiss AS
             SELECT MIN(pmiss_id) AS garder, pmiss_date, pmiss_reason, pmiss_id_campaign,
                    SUM(pmiss_count) AS total
               FROM t_postback_miss
           GROUP BY pmiss_date, pmiss_reason, pmiss_id_campaign'
        );
        $this->execute(
            'UPDATE t_postback_miss m JOIN tmp_pmiss t ON t.garder = m.pmiss_id
                SET m.pmiss_count = t.total'
        );
        $this->execute(
            'DELETE m FROM t_postback_miss m
               LEFT JOIN tmp_pmiss t ON t.garder = m.pmiss_id
              WHERE t.garder IS NULL'
        );
        $this->execute('DROP TEMPORARY TABLE tmp_pmiss');

        $this->table('t_postback_miss')
            ->changeColumn('pmiss_id_campaign', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 0,
                'comment' => '0 = campagne inconnue ; jamais NULL, sinon UNIQUE ne mord pas',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('t_postback_miss')
            ->changeColumn('pmiss_id_campaign', 'integer', ['signed' => false, 'null' => true])
            ->update();
        $this->execute('UPDATE t_postback_miss SET pmiss_id_campaign = NULL WHERE pmiss_id_campaign = 0');
    }
}
