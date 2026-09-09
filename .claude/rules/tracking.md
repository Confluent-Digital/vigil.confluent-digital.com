---
description: Chemin chaud — /c (clic + redirection). Latence, ULID, cache campagnes, pieges
paths:
  - "public/c.php"
  - "src/Core/**"
---

# Chemin chaud : le clic

`GET /c/<token>?<params>` -> enregistre le clic -> `302` vers le money site.

## Regles non negociables

**1. Aucun framework sur ce chemin.** `public/c.php` charge `src/Core/Database.php`
et rien d'autre. Pas de conteneur DI, pas de Twig, pas de session, pas de
Composer autoload complet (`require` explicite des 3 ou 4 fichiers utiles).
Mesure de reference : ~10 ms de boot Slim contre ~0,3 ms ici, pour un travail
utile de l'ordre de 0,5 ms.

**2. Toujours `302`, jamais `301`.** Un `301` est mis en cache par le
navigateur : les clics suivants du meme utilisateur ne repassent plus par Vigil
et ne sont **jamais comptes**. La panne est silencieuse et ne se voit que dans
l'ecart de reporting, des semaines plus tard. Envoyer aussi
`Cache-Control: no-store, no-cache, must-revalidate` et `Pragma: no-cache`.

**3. La campagne ne se resout pas par un SELECT.** `CampaignCache` sert le
couple campagne+publisher depuis APCu (TTL 60 s, repli Redis puis base). Un
SELECT par clic double le cout du chemin chaud pour une donnee qui change
quelques fois par jour. Toute modification de campagne, d'acces publisher ou de
destination **doit invalider le cache** (`CampaignCache::invalidate($token)`).

**4. Le clickid est un ULID, jamais un auto-increment.** Un identifiant
sequentiel en clair se devine : n'importe qui peut forger des conversions en
enumerant. L'ULID est aleatoire sur 80 bits, et ses 48 premiers bits sont
l'horodatage en millisecondes — il est donc triable par temps, ce qui evite la
fragmentation d'index d'un UUIDv4, **et il permet de retrouver la partition du
clic sans la stocker** (cf. `database.md`, elagage de partitions).

**5. Un seul INSERT, jamais de SELECT avant.** Le controle d'unicite du clic
(`click_is_unique`) se fait sur un `SETNX` Redis (cle `u:<campaign>:<hash ip+ua>`,
TTL 24 h), pas sur une requete. Si Redis est indisponible, on marque
`click_is_unique = 1` et on continue : **le redirect ne doit jamais echouer a
cause d'une dependance secondaire**.

**6. Le redirect part meme si l'ecriture echoue.** Ordre : resoudre la campagne,
construire l'URL, `header('Location')`, `fastcgi_finish_request()`, **puis**
ecrire le clic. Une base saturee doit couter des clics non traces, jamais des
utilisateurs bloques sur une page blanche.

## Anatomie de `c.php`

```
1. token = segment d'URL           -> 404 si absent
2. CampaignCache::get(token)       -> 404 si inconnu / campagne ou acces en pause
3. ulid = Ulid::generate()
4. url = MacroEngine::render(campaign_dest_url, contexte)
5. header('Location: '.url, true, 302) + no-store
6. fastcgi_finish_request()
7. INSERT t_click  (ou LPUSH Redis si CLICK_BUFFER_ENABLED=1)
```

## Parametres captes

| Ce qui arrive | Ou ca va |
|---|---|
| `s1`..`s5` | `click_sub1`..`click_sub5` (VARCHAR 255) |
| `cid`, `clickid`, `click_id`, `subid` | `click_external_id` — le clickid de la plateforme externe |
| tout le reste de la query | `click_raw_query` (JSON) |

**Ne jamais ajouter une colonne pour un nouveau parametre partenaire** : le JSON
`click_raw_query` est fait pour ca. Une colonne ne se justifie que si on filtre
ou agrege dessus.

## Pre-remplissage du kit mailing — donnee personnelle, jamais persistee

Douze variables definies par `docs/document_technique_kit_mailing.pdf` (page 8) :
`civ` `nom` `prenom` `email` `cp` `ville` `pays` `jour` `mois` `annee`
`naissance` `tel`. L'affilie substitue `[PRENOM]`, `[EMAIL]`... dans le lien
qu'il diffuse ; les valeurs doivent traverser Vigil jusqu'au formulaire du money
site pour qu'il s'affiche deja rempli.

**Ces valeurs ne sont jamais ecrites.** Ce sont des donnees directement
identifiantes, et la plateforme externe que Vigil remplace ne les stocke pas non
plus — le document technique le dit : « La plateforme ne stock aucune
information dans la base. » Les persister ferait de Vigil un fichier de
prospects, avec la duree de conservation, la base legale et le registre de
traitement que cela suppose.

Trois endroits fuyaient, et trois garde-fous les ferment. Ne pas les defaire :

| Fuite | Garde-fou |
|---|---|
| `click_raw_query` capte tout parametre inconnu | `Prefill::stripFrom()` les retire avant l'INSERT |
| nginx journalise `$request`, chaine de requete comprise | format `vigil_track` sur `$request_method $uri`, **pose au niveau serveur** et pas seulement sur `/c/` |
| l'URL de Vigil part au money site dans le `Referer` | `Referrer-Policy: no-referrer` sur `/c` |

Le format de journal est declare **au niveau serveur** volontairement : une
location ajoutee plus tard sans `access_log` explicite en herite. Le piege
rencontre : `try_files /dev/null @click` redirigeait vers une *named location*,
qui n'herite **pas** du `access_log` du bloc appelant — les clics retombaient
dans le journal general au format `combined`, avec la chaine de requete. Le
fastcgi_pass est donc direct dans `location ^~ /c/`, sans indirection.

Seule trace conservee : `t_click.click_has_prefill`, un booleen. Il repond a
« pourquoi le formulaire n'est-il pas pre-rempli ? » sans rien retenir.

Alias tolérés en entree (`firstname`, `lastname`, `mail`, `zip`, `phone`...) :
les routeurs d'emailing n'ont pas tous la meme convention, et un
pre-remplissage perdu en silence serait pire qu'un alias de trop.

## Macros disponibles

**Tracking** : `{clickid}` `{external_clickid}` `{campaign_id}` `{campaign_name}`
`{publisher_id}` `{publisher_token}` `{sub1}`..`{sub5}` `{timestamp}`
`{datetime}` `{ip}` `{country}` `{ua}`

**Pre-remplissage** : `{civ}` `{nom}` `{prenom}` `{email}` `{cp}` `{ville}`
`{pays}` `{jour}` `{mois}` `{annee}` `{naissance}` `{tel}`, plus `{prefill}` qui
rend d'un coup `&nom=X&prenom=Y` pour les champs renseignes — utile quand le
money site accepte nos noms de variables tels quels.

`{prefill}` est la **seule** macro non re-encodee : ses valeurs le sont deja une
par une, et l'encoder en bloc transformerait ses `&` et `=` en `%26` et `%3D`.

Toute valeur injectee dans une URL passe par `rawurlencode()`. `MacroEngine` le
fait ; ne jamais concatener une macro a la main.

## Le 404 n'est pas une page manquante

Un 404 sur `/c/` est un **lien en circulation qui envoie du trafic dans le
vide** : des courriels sont deja partis, des visiteurs cliquent, et rien ne le
signale. C'est la panne la plus silencieuse du systeme apres le 301.

Cinq causes y menent, que `CampaignCache::get()` ne distingue pas — c'est
normal, il est sur le chemin chaud :

| Cause | Reparable ? |
|---|---|
| `acces_suspendu` | oui, en un clic |
| `campagne_suspendue` | oui |
| `campagne_expiree` | oui (date de fin) |
| `publisher_inactif` | oui |
| `token_inconnu` | non — le jeton n'a jamais existe ou a ete regenere |
| `token_malforme` | non — scanner, faute de frappe |

`LinkMiss::diagnose()` pose la question **apres** le depart du visiteur : on est
hors du chemin nominal, mais le visiteur ne doit pas payer notre outillage.
Mesure : 1,00 ms pour un 404 contre 1,15 ms pour une redirection.

Trois regles a ne pas defaire :

1. **Agrege par (jeton, jour), jamais une ligne par clic.** Un lien mort peut
   recevoir des milliers de visites ; sans agregation, `t_link_miss` devient un
   levier d'amplification — une ecriture par requete, sur le chemin chaud, sans
   borne. Verifie : 40 visites -> une ligne, `miss_count = 40`.
2. **Les jetons malformes tiennent sous une seule ligne** (`(malforme)`), sinon
   un scanner ferait exploser la cardinalite.
3. **La page publique ne dit JAMAIS pourquoi.** « Cette campagne est en pause »
   renseigne un concurrent sur l'etat de vos operations. Le diagnostic va dans
   le journal, pas a l'ecran. Verrouille par
   `LinkMissTest::testLaPagePubliqueNeDivulguePasLaCause`.

La page est du HTML statique ecrit dans `LinkMiss::page()` : le chemin chaud n'a
ni Twig ni framework, et ce n'est pas un 404 qui va justifier de les charger.

Ecran : `/app/dead-links`, trie par volume — un lien mort a dix mille visites
merite qu'on s'en occupe avant celui qui en a trois.

## Montee en charge

Trois paliers, dans cet ordre. Ne pas sauter au dernier sans mesure.

| Palier | Levier | Ordre de grandeur |
|---|---|---|
| 1 | Ecriture synchrone, `innodb_flush_log_at_trx_commit=2` | ~2 000 clics/s |
| 2 | `CLICK_BUFFER_ENABLED=1` : `LPUSH` Redis + worker `ClickFlushTask` en `INSERT` groupes de 500 — **pas encore implemente**, le drapeau est sans effet | ~20 000 clics/s |
| 3 | Replica de lecture pour le back-office | decouple le reporting de l'ingestion |

Le palier 2 se paie d'une piece mobile : si le worker s'arrete, les clics
s'empilent dans Redis. `ClickFlushTask` doit publier sa profondeur de file dans
les logs, et une alerte se declencher au-dela de 50 000 entrees.

## Bots

`click_is_bot` est pose a partir d'une liste de user-agents et des plages IP de
datacenters. Un bot est **enregistre et redirige normalement** — on ne bloque
pas, on marque. Bloquer casse les crawlers legitimes des plateformes (Facebook,
Google Ads) qui verifient les URLs de destination avant de valider une annonce.
