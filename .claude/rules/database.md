---
description: Conventions BDD, schema Vigil, MariaDB/SQLyog, partitionnement, pieges
---

# Base de donnees

MariaDB **10.11 LTS** (conteneur `vigil_database`), `utf8mb4_unicode_ci`, InnoDB.

## Conventions de nommage

Identiques au DMP, pour que le passage d'un projet a l'autre soit sans surprise :
- tables prefixees `t_` (`t_click`, `t_campaign`) ;
- champs prefixes par le nom du modele (`click_id`, `click_date` dans `t_click`) ;
- cle primaire nommee `<prefixe>_id` ;
- cle etrangere `<prefixe>_id_<cible_sans_prefixe>` (`campaign_id_client`) ;
- index systematique sur les FK et les colonnes de filtre.

## MariaDB et SQLyog

SQLyog n'est plus maintenu depuis 2019 : il ne sait dialoguer **ni avec
`caching_sha2_password`** (defaut MySQL 8/9) **ni avec `ed25519`**. D'ou trois
choix, deja poses dans `docker-compose.yml` et `.docker/mariadb/init/` :

1. **MariaDB 10.11 LTS** et pas 11.x — SQLyog tique sur la chaine de version.
2. `--default-authentication-plugin=mysql_native_password`, et l'utilisateur
   `vigil` est cree explicitement en `IDENTIFIED VIA mysql_native_password`.
3. L'utilisateur est declare sur `'%'` et non `'localhost'` : la connexion
   arrive par le **tunnel SSH** de SQLyog, donc depuis la passerelle Docker.

Le port `3311` est bind sur `127.0.0.1` uniquement. **Ne jamais l'ouvrir sur
0.0.0.0** : `mysql_native_password` est un hash SHA-1, il n'a rien a faire
face a Internet. SQLyog s'y connecte par son onglet *SSH* (host SSH du serveur,
puis MySQL host `127.0.0.1` port `3311`).

## Schema

### Referentiel

| Table | Colonnes structurantes |
|---|---|
| `t_client` | `client_name`, `client_status` |
| `t_publisher` | `publisher_name`, `publisher_token` UNIQUE, `publisher_status` |
| `t_campaign` | `campaign_id_client`, `campaign_dest_url` (template a macros), `campaign_payout`, `campaign_postback_secret`, `campaign_postback_ips`, `campaign_status` |
| `t_campaign_publisher` | `cp_id_campaign`, `cp_id_publisher`, **`cp_token` UNIQUE** (le token du lien `/c/<token>`), `cp_payout` NULL, UNIQUE `(cp_id_campaign, cp_id_publisher)` |
| `t_campaign_postback` | `cpb_id_campaign`, `cpb_id_publisher` NULL, `cpb_url`, `cpb_method`, `cpb_on_status`, `cpb_status` |
| `t_user` | `user_email` UNIQUE, `user_role` enum(`admin`,`manager`,`publisher`), `user_id_publisher` NULL |

`cp_token` porte le couple campagne x publisher : un publisher ne peut pas
fabriquer un lien vers une campagne a laquelle il n'a pas acces, puisqu'il n'en
possede pas le token.

### Volumetrie

`t_click` — **la seule table qui grossit vite**.
- PK `(click_id, click_date)` — `click_id` en `BINARY(16)` (ULID).
- `PARTITION BY RANGE COLUMNS(click_date)`, une partition par mois.
- Colonnes : `click_id_campaign`, `click_id_publisher`, `click_date` DATETIME(3),
  `click_ip` VARBINARY(16) (`INET6_ATON`), `click_country`, `click_user_agent`,
  `click_referer`, `click_external_id`, `click_sub1`..`click_sub5`,
  `click_raw_query` JSON, `click_is_unique`, `click_is_bot`.

**Le partitionnement impose que la cle de partition fasse partie de toute cle
unique** — d'ou la PK composite. Consequence : chercher un clic par son seul
`click_id` scanne **toutes** les partitions. On ne le fait jamais : les 48
premiers bits de l'ULID sont l'horodatage, donc `pb.php` decode la date depuis
le clickid et ajoute `AND click_date BETWEEN <t-1j> AND <t+1j>`. L'elagage de
partitions redevient effectif sans stocker quoi que ce soit de plus.

`PartitionMaintenanceTask` cree les partitions **a l'avance** (3 mois glissants).
Une partition manquante fait echouer l'INSERT : c'est une panne d'ingestion
totale, silencieuse jusqu'au premier clic du mois. Le cron doit alerter.

`t_conversion`
- PK `conversion_id` BIGINT AUTO.
- **`UNIQUE (conversion_id_campaign, conversion_external_txid)`** — l'idempotence,
  cf. `postback.md`.
- `conversion_id_click` BINARY(16) + `conversion_click_date` (copie, pour
  retrouver le clic dans sa partition sans decoder l'ULID en SQL).
- `conversion_status` enum(`pending`,`approved`,`rejected`,`chargeback`),
  `conversion_payout`, `conversion_revenue`, `conversion_currency`,
  `conversion_date`, `conversion_date_update`, `conversion_ip`,
  `conversion_raw_query` JSON.

`t_postback_queue` — cf. `postback.md`. Index `(pq_status, pq_next_try_at)`.

### Pre-agregat

`t_stats_hourly` : `(stats_date_hour, stats_id_campaign, stats_id_publisher)`
UNIQUE, avec `stats_clicks`, `stats_clicks_unique`, `stats_conversions`,
`stats_payout`, `stats_revenue`.

**Le back-office ne lit jamais `t_click` ni `t_conversion` pour afficher des
stats.** Il lit `t_stats_hourly`, alimentee par `StatsAggregateTask` (cron
horaire, plus un rattrapage des 3 dernieres heures pour absorber les
conversions en retard). C'est ce qui garde les ecrans rapides quand `t_click`
depasse la centaine de millions de lignes.

## Pieges

- **Tout est en UTC en base**, affichage en `Europe/Paris`. Cent pour cent des
  ecarts de reporting avec une plateforme externe viennent de la. Le conteneur
  MariaDB, PHP-FPM et le cron tournent tous en `TZ=UTC`.
- `click_ip` est un `VARBINARY(16)` : `INET6_ATON()` a l'ecriture,
  `INET6_NTOA()` a la lecture. Un `VARCHAR(45)` couterait 3x l'espace sur la
  table la plus grosse du systeme, et empecherait les comparaisons de plages.
- **Chaque index de `t_click` est un cout a chaque ecriture.** N'en ajouter un
  qu'apres avoir verifie que la requete qui le motive ne peut pas taper
  `t_stats_hourly` a la place.
- MariaDB en `STRICT_TRANS_TABLES` : une valeur trop longue est **rejetee**, pas
  tronquee. Les entrees partenaires (user-agent, referer, subs) sont tronquees
  cote PHP avant l'INSERT, sinon un partenaire bavard provoque une 500 et un
  clic perdu.
- `ONLY_FULL_GROUP_BY` est actif : `GROUP BY` complet ou `MAX()`.
- `NULL != 1` vaut `NULL`, pas `TRUE`. Piege classique sur les colonnes
  `_status` / `_is_*` nullables — d'ou `NOT NULL DEFAULT` partout.
