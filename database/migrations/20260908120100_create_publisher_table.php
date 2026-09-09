<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreatePublisherTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('t_publisher', ['id' => 'publisher_id'])
            ->addColumn('publisher_name', 'string', ['limit' => 150])
            // Identifiant opaque du publisher, utilisable en macro dans les
            // URLs de destination sans exposer d'id sequentiel.
            ->addColumn('publisher_token', 'string', ['limit' => 16])
            ->addColumn('publisher_status', 'enum', [
                'values' => ['active', 'paused', 'archived'], 'default' => 'active',
            ])
            ->addColumn('publisher_contact_email', 'string', ['limit' => 190, 'null' => true])
            ->addColumn('publisher_notes', 'text', ['null' => true])
            ->addColumn('publisher_date_create', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['publisher_token'], ['unique' => true])
            ->addIndex(['publisher_status'])
            ->create();
    }
}
