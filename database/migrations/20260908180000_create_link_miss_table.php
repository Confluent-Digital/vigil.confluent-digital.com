<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Journal des liens morts.
 *
 * Un 404 sur `/c/` n'est pas une page manquante : c'est un lien EN CIRCULATION
 * qui envoie du trafic dans le vide. Des e-mails sont deja partis, des visiteurs
 * cliquent, et personne ne le sait — la panne est parfaitement silencieuse.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * AGREGE, JAMAIS UNE LIGNE PAR CLIC.
 *
 * Un lien mort peut recevoir des milliers de visites par jour, et un scanner
 * peut marteler des jetons au hasard. Une ligne par 404 ferait de cette table
 * un levier d'amplification : n'importe qui la ferait grossir sans limite, sur
 * le chemin chaud, avec une ecriture par requete.
 *
 * D'ou UNIQUE (jeton, jour) et un compteur. La cardinalite est bornee par le
 * nombre de jetons DISTINCTS vus dans la journee — et les jetons malformes sont
 * tous ranges sous une seule ligne de synthese.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class CreateLinkMissTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('t_link_miss', ['id' => 'miss_id'])
            ->addColumn('miss_token', 'string', [
                'limit' => 24,
                'comment' => 'Jeton demande, ou (malforme) pour la ligne de synthese',
            ])
            ->addColumn('miss_date', 'date')
            // La raison est diagnostiquee dans la branche 404, ou une requete
            // de plus ne coute rien : on est deja hors du chemin nominal.
            ->addColumn('miss_reason', 'enum', ['values' => [
                'token_inconnu',
                'acces_suspendu',
                'campagne_suspendue',
                'campagne_expiree',
                'publisher_inactif',
                'token_malforme',
            ]])
            ->addColumn('miss_count', 'integer', ['signed' => false, 'default' => 1])
            // Renseignes quand le jeton EXISTE mais ne redirige pas : c'est le
            // cas qui coute de l'argent, et celui qu'on peut reparer.
            ->addColumn('miss_id_campaign', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('miss_id_publisher', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('miss_first_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('miss_last_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('miss_last_ip', 'varbinary', ['limit' => 16, 'null' => true])
            ->addColumn('miss_last_referer', 'string', ['limit' => 500, 'null' => true])
            ->addIndex(['miss_token', 'miss_date'], ['unique' => true])
            ->addIndex(['miss_date', 'miss_count'])
            ->addIndex(['miss_reason'])
            ->create();
    }
}
