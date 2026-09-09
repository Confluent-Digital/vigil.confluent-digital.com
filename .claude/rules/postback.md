---
description: Postback entrant (/pb) et routeur S2S sortant — idempotence, securite, retry
paths:
  - "public/pb.php"
  - "src/Modules/Postback/**"
---

# Postback entrant et relai S2S

## Le postback entrant : `GET|POST /pb`

C'est la **seule** URL que le client configure sur son money site. Tout le
demultiplexage se fait ici.

| Parametre | Obligatoire | Role |
|---|---|---|
| `clickid` | oui | l'ULID rendu par `/c` |
| `txid` | fortement recommande | identifiant de transaction du money site |
| `payout` | non | montant ; sinon on prend `campaign_payout` |
| `status` | non | `pending` / `approved` / `rejected` / `chargeback` (defaut `approved`) |
| `s` | selon campagne | signature ou secret partage |

### 1. Idempotence — la protection la plus importante du systeme

`UNIQUE (conversion_id_campaign, conversion_external_txid)`.

Un money site qui retry son postback (timeout, cron de rattrapage, double clic
d'un operateur) enverrait sinon **deux fois la meme conversion**, et Vigil
relaierait deux fois vers la plateforme externe. L'ecriture se fait en
`INSERT ... ON DUPLICATE KEY UPDATE` : le deuxieme appel **met a jour** la ligne,
il n'en cree pas une seconde.

Si le money site n'envoie pas de `txid`, on retombe sur
`UNIQUE (campagne, click_id)` — une conversion par clic. C'est degrade : cela
interdit deux ventes sur un meme clic. Le noter dans la fiche campagne et
reclamer un `txid` au client.

### 2. Changement de statut, pas nouvelle ligne

`pending -> approved -> rejected|chargeback`. Un second postback sur le meme
`txid` avec un `status` different met a jour `conversion_status` et
`conversion_date_update`, puis **redeclenche le routage** vers les destinations
dont `cpb_on_status` contient le nouveau statut. Un `chargeback` doit pouvoir
etre repercute a la plateforme externe.

### 3. Authentification

Trois niveaux, cumulables, configures par campagne :
- **secret partage** : `campaign_postback_secret` compare en `hash_equals()`
  (jamais `==` : comparaison a temps constant) ;
- **HMAC** : `s = hmac_sha256(clickid|txid|payout, POSTBACK_SECRET)` quand le
  client sait le calculer ;
- **whitelist IP** : `campaign_postback_ips`, CSV de CIDR. Vide = pas de filtre.

Sans au moins un des trois, n'importe qui ayant vu un clickid peut fabriquer
des conversions. Une campagne sans aucun controle doit apparaitre en alerte
dans le back-office.

### 4. Repondre vite, relayer apres

`/pb` ecrit la conversion, empile les relais dans `t_postback_queue`, renvoie
`200 OK` — puis rend la main. **Aucun appel HTTP sortant n'est fait dans la
requete du money site** : un timeout de la plateforme externe ferait timeouter
le money site, qui retenterait, ou pire abandonnerait la conversion.

Reponse : `200` avec le corps `OK` (certaines plateformes exigent un corps
exact — le rendre configurable par campagne via `campaign_postback_response`).
Un `clickid` inconnu renvoie tout de meme `200` avec `OK`, et journalise :
un `404` declenche des files de retry cote client qui polluent les logs pour
une conversion qui, de toute facon, ne sera jamais rattachable.

## Le routeur sortant — trois portees

`t_campaign_postback` porte les destinations. Le couple
`(cpb_id_campaign, cpb_id_publisher)` en definit la portee :

| campagne | publisher | Se declenche pour |
|---|---|---|
| NULL | renseigne | **le pixel du publisher**, sur TOUTES ses conversions, quelle que soit la campagne |
| renseignee | NULL | la destination de cette campagne (la plateforme externe), toutes sources |
| renseignee | renseigne | ce couple precis — une exception negociee |
| NULL | NULL | **interdit** : contrainte `chk_cpb_portee` en base |

La portee publisher existe parce qu'un pixel de conversion recopie sur chaque
campagne redevient le probleme d'origine, transpose : dix campagnes, dix lignes
identiques ; le publisher change son pixel, dix modifications ; on en oublie
une, ses conversions cessent de remonter **en silence**. Il se declare une fois,
sur sa fiche (`/app/publishers/<id>/edit`).

L'invariant « au moins une des deux colonnes » vit dans la base **et** dans la
requete du routeur : ne pas se fier a la seule contrainte, une migration future
pourrait la retirer sans que le code s'en apercoive. Verrouille par
`tests/Feature/PostbackRouterTest` (trois portees, cumul, isolation entre
publishers, refus de la ligne sans portee).

Chaque destination :

| Colonne | Role |
|---|---|
| `cpb_url` | template a macros, ex. `https://tracker.ext/pb?cid={external_clickid}&sum={payout}` |
| `cpb_method` | `GET` ou `POST` |
| `cpb_body` / `cpb_headers` | pour les APIs JSON (CAPI Facebook, webhooks) |
| `cpb_on_status` | CSV des statuts declencheurs, defaut `approved` |
| `cpb_id_publisher` | `NULL` = toutes les sources ; sinon relai cible |

**Brancher une nouvelle plateforme ne doit jamais demander de code.** Si une
integration ne se decrit pas avec un template + des macros, c'est le moteur de
macros qu'il faut etendre, pas un `switch` par plateforme. C'est la lecon des
six `Conversions*Task` du DMP, une par plateforme, qu'il faut maintenir en
parallele.

### Deduplication des relais — et son revers

Un relai n'est pas empile si un relai **strictement identique** (meme conversion,
meme destination, meme URL rendue) est deja `pending` ou `sent`. Cela ferme la
fenetre de course entre deux postbacks simultanes.

Le revers : **une URL de relai sans `{status}` ne peut pas repercuter une
annulation.** Le relai du `chargeback` serait identique a celui de l'`approved`,
donc supprime comme doublon — et de toute facon la plateforme ne pourrait pas
les distinguer, deux appels identiques lui feraient compter deux ventes.

Une destination qui declare un `cpb_on_status` autre qu'`approved` doit donc
porter `{status}` dans son URL ou son corps. Le back-office le signale a
l'enregistrement. Verrouille par
`tests/Feature/PostbackRouterTest::testSansMacroStatutLAnnulationEstSupprimeeCommeDoublon`.

## Le refus est silencieux — d'ou le journal

`/pb` rend **toujours** `200`, meme quand il refuse. C'est delibere : un `404`
declencherait chez le money site des files de retry pour une conversion jamais
rattachable, et lui repondre « secret invalide » l'aiderait a le deviner.

Le revers : celui qui branche une campagne voit « HTTP 200 » et croit que ca
marche. `t_postback_miss` rend le refus visible, sur `/app/dead-links`.

Sept causes, agregees par (jour, cause, campagne). La derniere requete recue est
conservee — c'est elle qui permet de dire « il manque `&s=` » plutot que « ca ne
marche pas ». **Le secret y est masque** : il n'a rien a faire dans une table
que le back-office affiche.

**`pmiss_id_campaign` vaut 0, jamais NULL**, quand la campagne est inconnue :
dans un index UNIQUE, MySQL et MariaDB traitent chaque NULL comme DISTINCT, et
l'agregation ne mordait pas — precisement sur les cas qu'un scanner peut
inonder. Detecte par un test, corrige par la migration `20260909110000`.

## La file d'envoi

`t_postback_queue`, traitee par `PostbackFlushTask` (cron chaque minute).

- Backoff exponentiel : `1 min, 5, 15, 60, 360` puis `abandoned` (5 tentatives).
- `pq_http_code` et `pq_response` sont conserves **a chaque tentative**, succes
  comme echec. C'est la seule preuve utilisable le jour ou le client conteste.
- Timeout HTTP dur : 10 s de connexion, 15 s total. Une plateforme lente ne doit
  pas bloquer la file entiere.
- Traitement par lot de 200, `SELECT ... FOR UPDATE SKIP LOCKED` pour que deux
  workers ne se marchent pas dessus.
- **Un `2xx` ne suffit pas toujours** : certaines plateformes repondent `200`
  avec `{"status":"error"}`. Une destination peut declarer un motif de succes
  (`cpb_success_pattern`, regex sur le corps) ; vide = seul le code HTTP compte.

## Rejeu manuel

Le back-office doit exposer, par conversion, un bouton **Rejouer** qui remet les
relais en `pending`. Sans lui, la moindre panne d'une plateforme externe se
rattrape en SQL a la main, sous pression, en production.

## Reconciliation — a ecrire

`ReconciliationTask` **n'existe pas encore** : elle demande l'API de la
plateforme externe. Elle comparera, quand cette API sera connue, les
conversions relayees et celles qu'elle a effectivement enregistrees. L'ecart se
lit dans un ecran dedie. C'est ce qui manque toujours le jour ou les chiffres
divergent — et ils divergeront.
