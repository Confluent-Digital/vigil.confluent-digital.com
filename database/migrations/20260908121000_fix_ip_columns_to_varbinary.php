<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Les colonnes d'IP doivent etre VARBINARY, pas BINARY.
 *
 * `INET6_ATON()` rend 4 octets pour une IPv4 et 16 pour une IPv6. Une colonne
 * BINARY(16) est a longueur FIXE : MariaDB complete les 4 octets avec douze
 * zeros, et `INET6_NTOA()` relit ensuite les 16 octets comme une IPv6.
 * `172.80.18.1` ressortait en `ac50:1201::`.
 *
 * VARBINARY(16) conserve la longueur ecrite, donc l'aller-retour est exact pour
 * les deux familles d'adresses.
 *
 * Corrige par une nouvelle migration plutot qu'en editant la precedente : une
 * migration deja jouee ne se rejoue pas.
 */
final class FixIpColumnsToVarbinary extends AbstractMigration
{
    public function up(): void
    {
        $this->table('t_click')
            ->changeColumn('click_ip', 'varbinary', ['limit' => 16, 'null' => true])
            ->update();

        $this->table('t_conversion')
            ->changeColumn('conversion_ip', 'varbinary', ['limit' => 16, 'null' => true])
            ->update();

        // Les lignes ecrites avant ce correctif portent une IPv4 completee de
        // zeros : illisible telle quelle. On les remet a NULL plutot que de
        // laisser une adresse fausse — une donnee absente se voit, une donnee
        // fausse se croit.
        $this->execute(
            "UPDATE t_click SET click_ip = NULL
              WHERE click_ip IS NOT NULL AND RIGHT(click_ip, 12) = REPEAT(0x00, 12)"
        );
        $this->execute(
            "UPDATE t_conversion SET conversion_ip = NULL
              WHERE conversion_ip IS NOT NULL AND RIGHT(conversion_ip, 12) = REPEAT(0x00, 12)"
        );
    }

    public function down(): void
    {
        $this->table('t_click')
            ->changeColumn('click_ip', 'binary', ['limit' => 16, 'null' => true])
            ->update();
        $this->table('t_conversion')
            ->changeColumn('conversion_ip', 'binary', ['limit' => 16, 'null' => true])
            ->update();
    }
}
