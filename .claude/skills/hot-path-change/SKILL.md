---
name: hot-path-change
description: Toucher le chemin chaud de Vigil — public/c.php, public/pb.php, src/Core/ (Database, Ulid, MacroEngine, CampaignCache). Force les invariants de latence et de fiabilite (302 jamais 301, aucun framework, un seul INSERT, idempotence, encodage des macros) et la mesure avant/apres. A utiliser des qu'une modification touche le clic ou le postback entrant.
---

# Modifier le chemin chaud

Le chemin chaud, ce sont les deux seules routes par lesquelles passent le trafic
et l'argent. Une regression n'y est jamais visible tout de suite : elle se lit
dans un ecart de reporting, des semaines plus tard.

## Avant de toucher quoi que ce soit

Lis `.claude/rules/tracking.md` et `.claude/rules/postback.md`. Mesure l'etat
actuel, sinon tu ne sauras pas ce que ton changement a coute :

```bash
ab -n 5000 -c 50 "http://127.0.0.1:388/c/<token>" | grep -E "Requests per second|95%"
```

## Les sept invariants

1. **Aucun framework.** Pas de `vendor/autoload.php` complet, pas de conteneur
   DI, pas de session, pas de Twig dans `c.php` / `pb.php`. `require` explicite
   des trois ou quatre fichiers necessaires.
2. **`302`, jamais `301`**, avec `Cache-Control: no-store, no-cache, must-revalidate`.
3. **Un seul INSERT, aucun SELECT.** La campagne vient de `CampaignCache`,
   l'unicite d'un `SETNX` Redis.
4. **Le redirect part avant l'ecriture** : `header('Location')` puis
   `fastcgi_finish_request()` puis l'INSERT. Une base saturee coute des clics
   non traces, jamais des utilisateurs bloques.
5. **Aucune dependance secondaire bloquante.** Redis indisponible -> on continue.
6. **Idempotence** cote `/pb` : `INSERT ... ON DUPLICATE KEY UPDATE` sur
   `(campagne, txid)`. Jamais un `INSERT` simple.
7. **Aucun appel HTTP sortant dans `/pb`.** Les relais passent par
   `t_postback_queue`.

## Apres la modification

```bash
docker exec vigil_php php -l public/c.php public/pb.php

# Le code doit etre 302, pas 301
curl -I "http://127.0.0.1:388/c/<token>" | head -5

# Deux postbacks identiques -> une seule conversion, un seul relai
curl -s "http://127.0.0.1:388/pb?clickid=<ulid>&txid=TEST1&payout=10"
curl -s "http://127.0.0.1:388/pb?clickid=<ulid>&txid=TEST1&payout=10"
docker exec vigil_database mariadb -uvigil -p"$DB_PASSWORD" bd_vigil \
  -e "SELECT COUNT(*) FROM t_conversion WHERE conversion_external_txid='TEST1'"
# attendu : 1

docker exec vigil_php vendor/bin/phpunit --testsuite unit
ab -n 5000 -c 50 "http://127.0.0.1:388/c/<token>" | grep -E "Requests per second|95%"
```

Compare la mesure a celle d'avant. Une degradation de plus de 10 % se justifie
ou se corrige — elle ne se decouvre pas en production.

Termine en lancant l'agent `dev-verifier`.
