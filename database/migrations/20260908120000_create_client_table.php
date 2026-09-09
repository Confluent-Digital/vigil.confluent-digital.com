<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateClientTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('t_client', ['id' => 'client_id'])
            ->addColumn('client_name', 'string', ['limit' => 150])
            ->addColumn('client_status', 'enum', [
                'values' => ['active', 'paused', 'archived'], 'default' => 'active',
            ])
            ->addColumn('client_contact_email', 'string', ['limit' => 190, 'null' => true])
            ->addColumn('client_notes', 'text', ['null' => true])
            ->addColumn('client_date_create', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['client_status'])
            ->addIndex(['client_name'])
            ->create();
    }
}
