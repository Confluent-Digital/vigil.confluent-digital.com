<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Une destination de relai peut desormais appartenir a un publisher, pas
 * seulement a une campagne.
 *
 * Sans cela, le pixel de conversion d'un publisher devait etre recopie sur
 * chaque campagne ou il travaille : dix campagnes, dix lignes identiques a
 * maintenir. Il change son pixel, c'est dix modifications ; on en oublie une,
 * ses conversions cessent de remonter en silence. C'etait le probleme
 * d'origine de Vigil — « il faudrait plusieurs postbacks » — transpose de
 * l'autre cote de la chaine.
 *
 * Trois portees, definies par le couple de colonnes :
 *
 *   campagne NULL, publisher renseigne  -> le pixel du publisher, sur TOUTES
 *                                          ses conversions
 *   campagne renseignee, publisher NULL -> la plateforme externe de cette
 *                                          campagne, quelle que soit la source
 *   les deux renseignes                 -> ce couple precis, une exception
 *
 * Les deux NULL n'a pas de sens : la destination se declencherait sur toutes
 * les conversions de toutes les campagnes de tous les publishers. Une
 * contrainte CHECK l'interdit — l'invariant vit dans la base, pas seulement
 * dans le code qui ecrit.
 */
final class AllowPublisherScopedPostback extends AbstractMigration
{
    public function up(): void
    {
        $this->table('t_campaign_postback')
            ->changeColumn('cpb_id_campaign', 'integer', ['signed' => false, 'null' => true])
            ->update();

        $this->execute(
            'ALTER TABLE t_campaign_postback
                ADD CONSTRAINT chk_cpb_portee
                CHECK (cpb_id_campaign IS NOT NULL OR cpb_id_publisher IS NOT NULL)'
        );

        // La requete du routeur filtre desormais sur les deux colonnes : sans
        // cet index, elle degenere en parcours complet des que la table grossit.
        $this->table('t_campaign_postback')
            ->addIndex(['cpb_id_publisher', 'cpb_status'], ['name' => 'idx_cpb_publisher_status'])
            ->update();
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE t_campaign_postback DROP CONSTRAINT chk_cpb_portee');
        $this->table('t_campaign_postback')
            ->removeIndexByName('idx_cpb_publisher_status')
            ->update();

        // Les destinations sans campagne n'ont pas d'equivalent dans l'ancien
        // modele : on les retire plutot que de rendre la colonne NOT NULL sur
        // des lignes qui la violent.
        $this->execute('DELETE FROM t_campaign_postback WHERE cpb_id_campaign IS NULL');

        $this->table('t_campaign_postback')
            ->changeColumn('cpb_id_campaign', 'integer', ['signed' => false, 'null' => false])
            ->update();
    }
}
