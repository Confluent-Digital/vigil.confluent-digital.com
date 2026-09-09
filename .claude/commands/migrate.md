---
description: Cree une migration Phinx conforme aux conventions de schema Vigil, l'applique et verifie le status
argument-hint: <verbe_objet_table> (ex: create_conversion_table, add_secret_to_campaign)
---

Cree la migration Phinx `$ARGUMENTS` en suivant `.claude/rules/migrations.md` et
`.claude/rules/database.md`.

1. Verifie le dernier timestamp : `ls database/migrations/ | tail -3`. Le nouveau
   doit etre strictement superieur.
2. Genere le squelette :
   `docker exec vigil_php vendor/bin/phinx create <ClassNameCamelCase>`
3. Ecris la migration en respectant :
   - table prefixee `t_`, champs prefixes par le modele, PK `<prefixe>_id` ;
   - FK `<prefixe>_id_<cible>` **toujours** indexee ;
   - `enum` pour les statuts, `default` toujours specifie ;
   - `NOT NULL DEFAULT` par defaut (`NULL != 1` vaut `NULL` en MariaDB) ;
   - montants en `decimal(12,4)`, horodatages en `datetime` (pas `timestamp`) ;
   - aucune logique metier — un backfill se fait par une task dediee.
4. Si la table est partitionnee (`t_click`), applique le partitionnement en SQL
   brut dans la meme migration, et rappelle que les partitions suivantes sont
   creees par `PartitionMaintenanceTask`, pas par des migrations.
5. Applique : `docker exec vigil_php vendor/bin/phinx migrate`
6. Verifie : `phinx status` doit afficher `up`, puis `DESC <table>` en base pour
   confirmer que l'objet existe reellement.
