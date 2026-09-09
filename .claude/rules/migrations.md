---
description: Phinx — conventions, template, partitionnement, commandes
---

# Migrations — Phinx

**Repertoire** : `database/migrations/`, un fichier
`YYYYMMDDhhmmss_verbe_objet_table.php`. Config dans `phinx.php`.

## Regle absolue

Toute modification du schema passe par une migration. Jamais d'`ALTER TABLE`
manuel en console, jamais de `.sql` traine a cote. Une migration est versionnee,
rejouable et annulable.

## Template

```php
<?php
use Phinx\Migration\AbstractMigration;

class CreateClickTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('t_click', ['id' => false, 'primary_key' => ['click_id', 'click_date']])
            ->addColumn('click_id', 'binary', ['limit' => 16])
            ->addColumn('click_date', 'datetime', ['precision' => 3])
            ->addColumn('click_id_campaign', 'integer')
            ->addColumn('click_id_publisher', 'integer')
            ->addIndex(['click_id_campaign', 'click_date'])
            ->create();
    }
}
```

## Conventions

- Tables `t_*`, champs prefixes par le modele, PK `<prefixe>_id`.
- FK `<prefixe>_id_<cible>`, toujours indexee.
- `enum` natif pour les statuts, `default` **toujours** specifie.
- `NOT NULL DEFAULT` par defaut : `NULL != 1` vaut `NULL` en MySQL/MariaDB.
- Montants en `decimal` `['precision' => 12, 'scale' => 4]` — quatre decimales,
  les payouts en CPA se comptent souvent au millieme.
- Horodatages en `datetime` (pas `timestamp`) : pas de conversion de fuseau
  implicite, et pas de plafond en 2038.

## Partitionnement

Phinx ne sait pas declarer de partitions. Pour `t_click`, la migration cree la
table puis applique le partitionnement en SQL brut dans la meme migration :

```php
$this->execute("ALTER TABLE t_click PARTITION BY RANGE COLUMNS(click_date) (
    PARTITION p202609 VALUES LESS THAN ('2026-10-01'),
    PARTITION pmax    VALUES LESS THAN (MAXVALUE)
)");
```

L'ajout des partitions suivantes n'est **pas** une migration : c'est
`PartitionMaintenanceTask`, qui tourne tous les jours et cree trois mois
d'avance. Une partition manquante arrete l'ingestion en silence.

## Pieges

- Pas de logique metier dans une migration. Un backfill se fait par une task
  dediee, lancee une fois.
- Une migration deja jouee ne se rejoue pas : on corrige par une **nouvelle**
  migration.
- Timestamp du fichier strictement croissant (`ls database/migrations/ | tail -3`).
- `change()` pour le reversible, `up()`/`down()` explicites pour le destructif.
