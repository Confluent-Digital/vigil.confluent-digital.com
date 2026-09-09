---
description: PHPUnit, tests du chemin chaud, smoke tests, base de test
---

# Tests

Base de test dediee : **`bd_vigil_test`**, creee par
`.docker/mariadb/init/01-vigil.sh`. Jamais la base de dev — les tests tronquent.

```bash
docker exec vigil_php vendor/bin/phpunit
docker exec vigil_php vendor/bin/phpunit --testsuite unit
docker exec vigil_php vendor/bin/phpunit tests/Feature/PostbackIdempotenceTest.php
```

## Ce qui doit etre verrouille par un test

Ces cas sont ceux qui coutent de l'argent quand ils cassent :

| Fichier | Garantit |
|---|---|
| `Http/PostbackTest` | 5 postbacks identiques -> **une** conversion ; `approved` puis `chargeback` -> mise a jour, pas de seconde ligne ; mauvais secret -> conversion refusee mais `200` rendu ; clickid inconnu -> `200` sans rien creer ; sans `txid`, une conversion par clic |
| `Http/ClickRedirectTest` | code **302** (jamais 301), `no-store`, `Referrer-Policy: no-referrer`, 404 sur campagne ou acces en pause, pre-remplissage transmis **et non persiste** |
| `Unit/PostbackAuthTest` | secret compare en temps constant, whitelist CIDR (v4 et v6), cumul des deux controles |
| `Unit/MacroEngineTest` | toute macro injectee est `rawurlencode`ee ; `{prefill}` est la seule exception, et pourquoi |
| `Unit/UlidTest` | monotone dans la meme milliseconde, horodatage relisible (c'est lui qui elague les partitions) |
| `Unit/PrefillTest` | les douze champs du kit mailing ne survivent pas au filtre, alias compris |
| `Unit/TotpTest` | les six vecteurs officiels de la RFC 6238, fenetre de +/-1 pas, anti-rejeu |
| `Unit/CryptoTest` | le secret TOTP est illisible au repos, message altere -> null |
| `Feature/PostbackRouterTest` | macros resolues a l'empilement, pas de relai double, revers de la deduplication sans `{status}` |
| `Feature/PostbackRetryTest` | bareme 1/5/15/60 min, `abandoned` a la 5e, reponse conservee meme en echec |
| `Feature/StatsAggregateTest` | comptages exacts, recalcul non cumulatif, conversion tardive remontee dans l'heure du **clic** |
| `Feature/PartitionMaintenanceTest` | 3 mois d'avance, idempotence, et un clic du mois prochain qui s'insere vraiment |

## Trois suites, trois usages

```bash
docker exec vigil_php vendor/bin/phpunit                      # tout
docker exec vigil_php vendor/bin/phpunit --testsuite unit     # rapide, sans base
docker exec vigil_php vendor/bin/phpunit --testsuite feature  # base de test, transactionnel
docker exec vigil_php vendor/bin/phpunit --testsuite http     # chemin chaud, serveur requis
```

- **`unit`** : logique pure, quelques millisecondes. A lancer en continu.
- **`feature`** : `bd_vigil_test`, chaque test en transaction annulee.
- **`http`** : tape reellement `http://vigil_nginx` (`TEST_BASE_URL`). C'est le seul
  moyen fidele de prouver un code 302, un en-tete ou le comportement derriere
  PHP-FPM. Ces tests ecrivent dans la base de **developpement** — celle que sert
  le conteneur — et nettoient eux-memes leurs lignes, prefixees `PHPUNIT-`.
  Ils se marquent `skipped` si le serveur ne repond pas.

## Deux pieges de la base de test

- **PDO ne sait pas imbriquer les transactions** : il leve « There is already an
  active transaction ». Tout code appele depuis un test transactionnel doit
  verifier `inTransaction()` avant d'ouvrir la sienne — c'est ce que fait
  `PostbackFlushTask::claim()`.
- **`ALTER TABLE` provoque un commit implicite** : un test de partitionnement ne
  peut pas etre transactionnel. `PartitionMaintenanceTest` n'herite donc pas de
  `DatabaseTestCase` et nettoie a la main.

## Tests metier : transactionnels

Un test qui touche la base ouvre une transaction et **`ROLLBACK`** a la fin. Pas
de fixtures a nettoyer, pas d'etat qui fuit d'un test a l'autre.

## Interdit en test

**Ne jamais emprunter un compte reel pour un essai manuel**, et ne jamais
toucher `t_user`, `t_user_recovery_code` ni `t_user_email_code` en nettoyant :
ces tables portent l'acces des personnes. Un secret TOTP efface n'est pas
recuperable — chiffre au repos, absent des sauvegardes, `log_bin` desactive.
Voir `.claude/rules/auth.md`.


**Aucun appel HTTP reel vers une plateforme externe.** Le client HTTP est
injecte et remplace par un double dans les tests. Un relai envoye pour de vrai
depuis une suite de tests pollue le reporting d'un partenaire, et ne se retire
pas.

## Smoke tests

```bash
curl -I http://127.0.0.1:388/c/<token>     # attendu : 302 + Location
curl -s "http://127.0.0.1:388/pb?clickid=<ulid>&txid=T1&payout=12"   # attendu : 200 OK
curl -I http://127.0.0.1:388/app/          # attendu : 200 ou 302 vers login
```

## Charge

Avant toute mise en production, mesurer le chemin chaud — c'est le seul chiffre
qui compte pour dimensionner :

```bash
ab -n 20000 -c 100 "http://127.0.0.1:388/c/<token>"
```

Relever req/s, p95, et la profondeur de la file Redis si le buffer est actif.
Une mesure vaut mieux que le choix d'un framework.
