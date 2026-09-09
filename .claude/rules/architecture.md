---
description: Vue d'ensemble Vigil — flux, entites, decoupage du code
---

# Architecture Vigil

## Ce que fait Vigil

Vigil est un **routeur de postback** autant qu'un tracker de clics. Le probleme
qu'il resout : un money site n'accepte generalement **qu'une seule URL de
postback**, alors que plusieurs campagnes de plusieurs plateformes externes
pointent vers lui. Vigil s'intercale et demultiplexe.

```
User
  -> lien plateforme externe
  -> /c/<token>            (Vigil enregistre le clic + le clickid externe)
  -> 302 money site        (?clickid=<ulid Vigil>&utm_*=...)
  -> validation / paiement
  -> /pb?clickid=...       (postback UNIQUE, le seul que le client configure)
  -> enregistrement conversion
  -> relai S2S vers N destinations (plateforme externe, autre tracker, CAPI...)
```

Le client ne configure **qu'un** postback. Vigil sait, grace au clic, vers
quelle plateforme externe et avec quel `external_clickid` relayer.

## Entites

| Entite | Table | Role |
|---|---|---|
| Client | `t_client` | l'annonceur, proprietaire du money site. 1..N campagnes |
| Campagne | `t_campaign` | une offre : URL de destination, payout, secret de postback |
| Publisher | `t_publisher` | la source de trafic. Accede a 1..N campagnes |
| Acces | `t_campaign_publisher` | le couple campagne x publisher, **porte le token du lien** |
| Clic | `t_click` | table volumetrique, partitionnee par mois |
| Conversion | `t_conversion` | idempotente sur `(campagne, txid externe)` |
| Destination | `t_campaign_postback` | les N relais S2S d'une campagne |
| File | `t_postback_queue` | un relai a envoyer, avec retry |
| Stats | `t_stats_hourly` | pre-agregat lu par le back-office |

**Le token du lien porte le couple campagne + publisher.** Un seul parametre
dans l'URL (`/c/<token>`), donc une seule resolution de cache par clic, et
aucun moyen pour un publisher de fabriquer un lien vers une campagne a laquelle
il n'a pas acces.

## Deux chemins d'execution, volontairement separes

| Chemin | Entree | Pile | Contrainte |
|---|---|---|---|
| **Chaud** | `/c/<token>`, `/pb` | `public/c.php`, `public/pb.php` — PDO seul, **aucun framework** | latence et debit |
| **Froid** | tout le reste | Slim 4 + Twig + Bootstrap | confort de developpement |

Ne **jamais** faire passer le chemin chaud par Slim : le boot du framework
(~10 ms) domine largement le travail utile (~0,5 ms). Voir `tracking.md`.

## Decoupage du code

```
public/
  c.php            chemin chaud — clic + redirection
  pb.php           chemin chaud — postback entrant
  index.php        front controller Slim 4 (back-office)
src/
  Core/            Database (PDO), Ulid, MacroEngine, CampaignCache
  Modules/<Nom>/   Controllers/, Models/, Repositories/, Tasks/
  Views/           Twig — layouts/base.html.twig, pages/
database/migrations/  Phinx
bin/task_runner.php   point d'entree des tasks cron
```

Regles detaillees : `tracking.md`, `postback.md`, `database.md`,
`migrations.md`, `frontend.md`, `testing.md`, `docker-commands.md`, `deploy.md`.
