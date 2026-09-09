#!/bin/bash
# Joue une seule fois, a la creation du volume.
set -e

mysql -uroot -p"${MARIADB_ROOT_PASSWORD}" <<SQL
-- Base de test isolee pour PHPUnit (jamais la base de dev).
CREATE DATABASE IF NOT EXISTS \`${MARIADB_DATABASE}_test\`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- L'utilisateur applicatif est force en mysql_native_password : SQLyog ne
-- sait dialoguer ni avec caching_sha2_password ni avec ed25519. Le '%' est
-- necessaire car la connexion arrive par le tunnel SSH, donc depuis la
-- passerelle Docker et non depuis localhost.
CREATE USER IF NOT EXISTS '${MARIADB_USER}'@'%'
  IDENTIFIED VIA mysql_native_password USING PASSWORD('${MARIADB_PASSWORD}');
ALTER USER '${MARIADB_USER}'@'%'
  IDENTIFIED VIA mysql_native_password USING PASSWORD('${MARIADB_PASSWORD}');

GRANT ALL PRIVILEGES ON \`${MARIADB_DATABASE}\`.* TO '${MARIADB_USER}'@'%';
GRANT ALL PRIVILEGES ON \`${MARIADB_DATABASE}_test\`.* TO '${MARIADB_USER}'@'%';
FLUSH PRIVILEGES;
SQL
