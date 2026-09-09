<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Secret de postback au niveau du CLIENT.
 *
 * Le secret vivait uniquement sur la campagne. Or l'objet meme de Vigil est de
 * ne donner au client **qu'une seule** URL de postback pour toutes ses
 * campagnes : avec un secret par campagne, le `&s=` differait de l'une a
 * l'autre, et le client se retrouvait a en configurer autant qu'avant. Le
 * probleme qu'on resolvait reapparaissait un cran plus bas.
 *
 * Le secret de campagne n'est pas supprime : il reste accepte, et sert
 * d'exception negociee quand un annonceur veut cloisonner une campagne.
 */
final class AddPostbackSecretToClient extends AbstractMigration
{
    public function change(): void
    {
        $this->table('t_client')
            ->addColumn('client_postback_secret', 'string', [
                'limit' => 64, 'null' => true, 'after' => 'client_status',
            ])
            ->update();
    }
}
