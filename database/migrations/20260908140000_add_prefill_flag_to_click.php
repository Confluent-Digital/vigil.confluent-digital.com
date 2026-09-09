<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Marqueur de pre-remplissage sur le clic.
 *
 * Un booleen, jamais les valeurs. Les douze champs du kit mailing (nom,
 * prenom, adresse electronique, telephone, date de naissance...) transitent
 * vers le money site et ne sont pas persistes — cf. src/Core/Prefill.php.
 *
 * Cette colonne repond a la seule question qu'on se pose en exploitation :
 * « pourquoi le formulaire n'est-il pas pre-rempli ? ». Sans elle, impossible
 * de distinguer un affilie qui n'envoie rien d'un bogue de notre cote. Un
 * octet sur la table la plus volumineuse, contre une donnee personnelle
 * conservee sans duree ni base legale : l'arbitrage est vite fait.
 */
final class AddPrefillFlagToClick extends AbstractMigration
{
    public function change(): void
    {
        $this->table('t_click')
            ->addColumn('click_has_prefill', 'boolean', [
                'default' => 0,
                'after'   => 'click_is_bot',
                'comment' => 'Le lien portait des valeurs de pre-remplissage (valeurs non conservees)',
            ])
            ->update();
    }
}
