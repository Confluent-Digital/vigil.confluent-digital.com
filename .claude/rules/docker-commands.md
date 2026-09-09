---
description: Commandes Docker, conteneurs, tasks, composer, base
---

# Commandes

## Demarrage

```bash
./init.sh                 # installation / remise en route complete
./init.sh --no-nginx      # sans le vhost hote (pas de sudo)
./init.sh --force-nginx   # ecrase le vhost hote (sauvegarde d'abord)
./init.sh --no-build      # sans reconstruire l'image PHP
./init.sh --down          # arret, donnees conservees
```

Idempotent : un `.env` existant n'est jamais ecrase, un vhost existant n'est
remplace qu'avec `--force-nginx`. Le script installe le vhost hote dans
`/data/nginx/vigil.confluent-digital.com.conf` (rendu depuis
`.docker/nginx/host/vhost.conf.template`), **teste la configuration avec
`nginx -t` avant de recharger**, et retire le vhost si le test echoue : une
conf invalide couperait tous les sites de la machine, pas seulement Vigil.

## Conteneurs

| Conteneur | Image | Port hote (127.0.0.1) |
|---|---|---|
| `vigil_nginx` | `docker-registry.confluent-digital.com/nginx:1.28-alpine` | `388` |
| `vigil_php` | `vigil/php:8.3-fpm` — build local depuis `.docker/php/Dockerfile` | `988` |
| `vigil_database` | `mariadb:10.11` | `3311` |
| `vigil_redis` | `redis:7-alpine` | `6391` |
| `vigil_mailhog` | `mailhog/mailhog` — alias reseau **`mailhog`** | SMTP `1029`, interface `8029` |

Repertoire de travail dans le conteneur PHP :
`/data/www/vigil.confluent-digital.com` (identique au chemin hote).

L'image PHP est **construite** a partir de l'image du parc
(`docker-registry.confluent-digital.com/php:slim-8.3-fpm`), completee de
**apcu** et **redis** — absentes de la base et indispensables au chemin chaud.
`.docker/php/php.ini` met aussi `date.timezone = UTC` (la base pose
`Europe/Paris`) et `xdebug.mode = off` : Xdebug charge en mode `develop` coute
30 a 50 % par requete, ce qui est inacceptable sur `/c` et `/pb`. Le repasser a
`debug,develop` ponctuellement en dev, jamais en production.

```bash
docker compose build vigil_php  # apres modification du Dockerfile
docker compose up -d            # demarrer
docker compose ps               # etat
docker compose restart vigil_php
docker logs -f vigil_php
```

## PHP / Composer

```bash
docker exec vigil_php composer install
docker exec vigil_php composer dump-autoload -o
docker exec vigil_php php -l public/c.php
docker exec vigil_php vendor/bin/phpunit
docker exec vigil_php vendor/bin/phpstan analyse
```

## Base

```bash
# Console (mot de passe dans .env)
docker exec -it vigil_database mariadb -uvigil -p bd_vigil

# Depuis l'hote (ce que fait SQLyog a travers son tunnel SSH)
mysql -h 127.0.0.1 -P 3311 -u vigil -p bd_vigil

# Migrations
docker exec vigil_php vendor/bin/phinx migrate
docker exec vigil_php vendor/bin/phinx status
```

## Tasks (cron)

Toutes les tasks passent par `bin/task_runner.php` :

```bash
docker exec vigil_php php bin/task_runner.php <Module> <TaskClass> <methode>
```

Planification dans `config/cron` (`crontab -u www-data config/cron`).

| Task | Frequence | Role | Etat |
|---|---|---|---|
| `Postback PostbackFlushTask send` | chaque minute | vide `t_postback_queue`, backoff 1/5/15/60/360 min puis abandon | ecrite |
| `Stats StatsAggregateTask run` | chaque heure | alimente `t_stats_hourly` (+ rattrapage 3 h) | ecrite |
| `Maintenance PartitionMaintenanceTask ensure` | quotidien | cree les partitions de `t_click` 3 mois a l'avance | ecrite |
| `Click ClickFlushTask flush` | chaque minute | vide le buffer Redis (si `CLICK_BUFFER_ENABLED=1`) | **pas encore ecrite** |
| `Postback ReconciliationTask compare` | quotidien | ecart Vigil / plateforme externe | **pas encore ecrite** |

Les deux dernieres sont decrites dans `tracking.md` et `postback.md` comme
cibles : `CLICK_BUFFER_ENABLED` n'a donc pas encore d'effet, et la
reconciliation demande l'API de la plateforme externe.

Logs : `logs/tasks/<module>/<task>/YYYYMMDD_*.log`.

## Outils d'exploitation

```bash
# Remet a zero la verification en deux etapes d'un compte (telephone perdu ET
# codes de secours epuises). Sans --confirme, affiche seulement ce qui serait fait.
docker exec vigil_php php bin/reset-2fa.php <email> --confirme
```

## Cache Twig

```bash
rm -rf cache/twig/*
```
Le hook `twig-cache-clear.sh` le fait automatiquement a chaque edition d'un
`.twig`. Le piege « ma modif de template n'apparait pas » vient toujours de la.
