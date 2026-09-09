# CLAUDE.md

Guidance pour Claude Code (claude.ai/code) sur le depot **Vigil**.

## Style de reponse
- Ne pas afficher de code dans les messages texte (les diffs sont visibles dans
  les tool calls).
- Reponses minimales : liste courte des fichiers modifies et ce qui a change.
- Pas de recap detaillee, pas de blocs de code repetes.

## Fin de dev — verification runtime (agent `dev-verifier`)
A la fin de tout dev qui touche du code, avant de committer ou de conclure :
- **Zone sensible** (`public/c.php`, `public/pb.php`, `src/Core/`,
  `t_conversion` et sa contrainte UNIQUE, `PostbackFlushTask`, migrations,
  partitionnement) -> **lancer directement** l'agent `dev-verifier`
  (`Agent(subagent_type: "dev-verifier")`) et rapporter le verdict OK/KO.
- **Changement mineur** -> **proposer** en fin de reponse : « Veux-tu que je
  lance l'agent dev-verifier ? » (une seule fois, pas a chaque tour).
- L'agent boote l'app, joue PHPUnit, verifie migrations et partitions, et prouve
  le metier par test DB **transactionnel (ROLLBACK)**. Il **n'envoie jamais** de
  postback reel a un tiers. Il ne remplace pas `php-code-reviewer` (revue
  statique avant commit) — les deux sont complementaires.

## Ce qu'est Vigil

Un **routeur de postback** autant qu'un tracker de clics. Le probleme resolu :
un money site n'accepte qu'**une seule** URL de postback, alors que plusieurs
campagnes de plusieurs plateformes externes pointent vers lui. Vigil s'intercale
et demultiplexe.

```
User -> lien plateforme externe -> /c/<token> (clic enregistre)
     -> 302 money site -> validation / paiement
     -> /pb (postback UNIQUE, le seul que le client configure)
     -> conversion -> relai S2S vers N destinations
```

Entites : Client (annonceur) 1..N Campagnes ; Publisher (source de trafic)
accedant a 1..N campagnes via `t_campaign_publisher`, qui **porte le token du
lien**. Puis Clic, Conversion, Destinations de relai, File d'envoi, Stats
pre-agregees.

## Stack

PHP **8.3** + **Slim 4** + Twig + Bootstrap 5.3 / DataTables,
**MariaDB 10.11 LTS**, Redis 7, Phinx pour les migrations, Docker.
PSR-4 sous `App\` dans `src/`.

## Deux chemins d'execution, volontairement separes

| Chemin | Entree | Pile |
|---|---|---|
| **Chaud** | `/c/<token>`, `/pb` | `public/c.php`, `public/pb.php` — PDO seul, **aucun framework** |
| **Froid** | tout le reste | Slim 4 + Twig (back-office) |

Ne jamais faire passer le chemin chaud par Slim : le boot du framework (~10 ms)
domine largement le travail utile (~0,5 ms). Le choix du framework n'est pas le
facteur limitant de ce projet — la separation des chemins l'est.

## Les invariants qui coutent de l'argent quand ils cassent

1. **`302`, jamais `301`** sur une redirection de clic. Un `301` est mis en cache
   par le navigateur : les clics suivants ne sont plus jamais comptes. Panne
   silencieuse, visible seulement dans l'ecart de reporting.
2. **`UNIQUE (campagne, txid)` sur `t_conversion`.** Un money site qui retry son
   postback creerait sinon deux conversions, relayees deux fois chez le
   partenaire — irrattrapable.
3. **Aucun appel HTTP sortant dans `/pb`.** Les relais passent par
   `t_postback_queue` et un worker. Sinon un timeout de plateforme externe fait
   timeouter le money site.
4. **Le clickid est un ULID**, jamais un auto-increment : un identifiant
   sequentiel en clair se devine, et les conversions se forgent.
5. **Tout est en UTC en base**, affiche en `Europe/Paris`. Cent pour cent des
   ecarts de reporting viennent de la.
6. **Le back-office ne lit jamais `t_click`** pour afficher des stats, mais
   `t_stats_hourly`.
7. **Une partition manquante sur `t_click` arrete l'ingestion en silence.**
8. **Le mot de passe seul n'authentifie personne.** `Auth::attemptPassword()`
   ouvre une session *en attente* ; seul `Auth::completeLogin()`, apres le
   second facteur, pose `$_SESSION['user']` — le seul que lit `AuthMiddleware`.

## Regles detaillees (`.claude/rules/`)

| Fichier | Contenu |
|---|---|
| `architecture.md` | flux, entites, decoupage du code, chemins chaud/froid |
| `auth.md` | connexion en deux temps, TOTP, codes de secours, repli courriel assume, plafonnement |
| `tracking.md` | `/c` — latence, ULID, cache campagnes, paliers de montee en charge, bots |
| `postback.md` | `/pb` et routeur S2S — idempotence, authentification, macros, retry, reconciliation |
| `database.md` | conventions, schema, MariaDB/SQLyog, partitionnement, pieges |
| `migrations.md` | Phinx, conventions de schema, partitionnement |
| `frontend.md` | Bootstrap 5.3, DataTables, selectpicker, lecture des chiffres, ecrans indispensables |
| `testing.md` | PHPUnit, ce qui doit etre verrouille, tests transactionnels, mesure de charge |
| `deploy.md` | ordre des operations, checklist, zones sensibles |
| `docker-commands.md` | conteneurs, ports, tasks cron, base |
| `sandbox.md` | bac a sable `/sandbox` : boucle complete simulee, absent en production |

## Environnement

**`./init.sh`** fait tout : `.env` + secrets, repertoires, verification des
ports, build de l'image PHP, demarrage des conteneurs, attente de MariaDB,
`composer install`, migrations, vhost hote dans `/data/nginx/` (avec `nginx -t`
avant reload), puis une serie de verifications. `./init.sh -h` pour les options,
`./init.sh --down` pour arreter.

Ports exposes sur `127.0.0.1` uniquement :
nginx `388`, PHP-FPM `988`, MariaDB `3311`, Redis `6391`.

**SQLyog** : MariaDB 10.11 (pas 11.x), utilisateur en `mysql_native_password`
declare sur `'%'`, connexion par **tunnel SSH** vers `127.0.0.1:3311`. Ne jamais
exposer le port sur `0.0.0.0`. Detail dans `.claude/rules/database.md`.
