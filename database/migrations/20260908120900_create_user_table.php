<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateUserTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('t_user', ['id' => 'user_id'])
            ->addColumn('user_email', 'string', ['limit' => 190])
            ->addColumn('user_password', 'string', ['limit' => 255])
            ->addColumn('user_name', 'string', ['limit' => 150])
            ->addColumn('user_role', 'enum', [
                'values' => ['admin', 'manager', 'publisher'], 'default' => 'manager',
            ])
            // Renseigne pour un role `publisher` : c'est le scope de TOUTES ses
            // requetes. Filtrer seulement dans l'interface laisserait un IDOR.
            ->addColumn('user_id_publisher', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('user_status', 'enum', [
                'values' => ['active', 'disabled'], 'default' => 'active',
            ])
            ->addColumn('user_date_create', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('user_date_login', 'datetime', ['null' => true])
            ->addIndex(['user_email'], ['unique' => true])
            ->addIndex(['user_id_publisher'])
            ->create();
    }
}
