---
description: Conventions UI back-office — Bootstrap 5.3, DataTables, Twig, dark mode
paths:
  - "src/Views/**"
  - "public/assets/**"
---

# Back-office (Slim 4 + Twig + Bootstrap 5.3)

Conventions reprises du DMP (`service.comparer-changer.fr`) pour qu'un
developpeur passe d'un projet a l'autre sans reapprendre.

## Assets : zero CDN

Tout est servi depuis `public/assets/dist/`. Packages via Yarn, copies par
`scripts/copy-assets.sh`. Un CDN ajoute une dependance externe sur un
back-office interne, et casse hors ligne.

Pile : Bootstrap 5.3, jQuery, DataTables 1.13, bootstrap-select, Chart.js 4.4,
Bootstrap Icons.

## DataTables

- Boutons : **Agrandir** (toggle expand colonnes), **Colonnes** (colvis),
  Copy / CSV / Excel.
- Colonnes texte (Client, Campagne, Publisher, Statut) : filtre **select** en
  `tfoot`, pas un champ libre.
- Colonnes numeriques : `data-order` avec la valeur brute pour le tri,
  `columnDefs` `type: 'num-fmt'`.
- Ligne **TOTAL** en `tfoot` — via `footerCallback` en AJAX, via Twig en rendu
  serveur. **Le total porte sur le jeu filtre**, pas sur la page courante.
- Server-side sur les listes de clics et de conversions : ces tables se comptent
  en millions, un `draw` client les chargerait entierement.

## Selectpickers

- `selectpicker` pour toute selection d'entite (Client, Campagne, Publisher).
- Rechargement dynamique : `$sel.selectpicker('destroy').empty()` puis
  `$sel.selectpicker()`. **Jamais `.selectpicker('refresh')`** — il laisse des
  options fantomes.
- Delegation d'evenement :
  `$(document).on('changed.bs.select', '#selectX', ...)` pour survivre aux
  `destroy`.

## Filtres en cascade (Client -> Campagne -> Publisher)

Endpoints dedies sous `/app/filters/*`, optgroups **Actives / Inactives** avec
compteur, inactives en `text-muted`.

## Lecture des chiffres

- **« Vide » ne se lit pas « zero ».** Une campagne sans clic affiche `0`, une
  campagne dont les stats ne sont pas encore agregees affiche `—`. Confondre les
  deux fait conclure a une panne de tracking, ou l'inverse.
- Les taux (CR, EPC) affichent leur denominateur au survol. Un taux de
  conversion de 100 % sur 1 clic n'est pas un taux de conversion.
- Toutes les dates sont stockees en UTC et **affichees en `Europe/Paris`**, avec
  le fuseau visible dans l'en-tete de l'ecran. C'est la premiere chose que
  regarde un client qui conteste un chiffre.

## Ecrans indispensables

| Ecran | Pourquoi |
|---|---|
| Detail conversion + bouton **Rejouer** | rattraper une panne de plateforme externe sans SQL a la main |
| Journal des relais (`t_postback_queue`) | code HTTP et corps de reponse, succes **et** echecs |
| Generateur de lien par acces publisher | copie en un clic, avec les macros documentees a cote |
| Testeur de postback | forge un appel `/pb` sur une campagne, en mode simulation |
| Reconciliation | ecart Vigil / plateforme externe |

## Dark mode

Bascule dans le dropdown utilisateur (IDs `#themeToggle`, `#themeIcon`).
Badges `bg-warning` en texte noir, rouge adouci (`#f87171`).

## Assets : `src/` versionne, `dist/` genere

```
public/assets/src/    nos feuilles et scripts — VERSIONNES
public/assets/dist/   servi par nginx — ENTIEREMENT GENERE, gitignore
```

`scripts/copy-assets.sh` construit `dist/` a partir de `node_modules/` (Bootstrap,
Bootstrap Icons) **et** de `src/`. `init.sh` l'appelle, et verifie la presence des
quatre fichiers attendus.

**Ne jamais editer directement dans `dist/`** : le repertoire est gitignore, un
fichier ecrit dedans n'existe que sur la machine qui l'a produit. C'est arrive —
tout le systeme de design a vecu 36 Ko hors du depot, invisible, jusqu'a ce
qu'un 404 sur `bootstrap-icons.css` en production le revele.

## Purger les caches

```bash
./bin/cache-clear.sh            # Twig, APCu, OPcache
./bin/cache-clear.sh --assets   # + reconstruction de dist/
./bin/cache-clear.sh --all      # + Redis (demande confirmation)
```

Le script **redemarre `vigil_php`**, et c'est le seul moyen fiable : les trois
caches vivent dans le processus PHP-FPM ou dans le systeme de fichiers du
conteneur. En particulier, `apcu_clear_cache()` lance en CLI ne touche PAS la
memoire partagee de PHP-FPM — c'est un autre processus, avec son propre segment.
Un `docker exec ... php -r 'apcu_clear_cache();'` donnerait l'illusion d'avoir
purge sans rien purger.

Le redemarrage coute une a deux secondes d'indisponibilite : a eviter en pleine
pointe. Le script attend que `/health` reponde avant de rendre la main, pour ne
pas annoncer « purge terminee » sur un service encore en train de remonter.

`--redis` demande confirmation : Redis ne porte pas que du cache, il tient les
compteurs d'unicite des clics (24 h). Les vider fait recompter comme uniques des
visiteurs deja venus, et gonfle les statistiques jusqu'au lendemain.

## Cache Twig

En developpement, Twig **ne cache pas** : chaque requete recompile. C'est pour
cela qu'une modification de template apparait immediatement — et aussi pourquoi
un defaut lie au cache ne se voit qu'en production.

En production, le cache vit **dans le conteneur** (`/tmp/vigil-twig`, reglable
par `TWIG_CACHE_DIR`), jamais sur le volume monte : c'est du PHP compile,
regenerable, et le mettre en partage avec l'hote exposait a des conflits de
droits illisibles. Redemarrer `vigil_php` purge le cache — il n'y a plus de
`rm -rf cache/twig/*` a se rappeler au deploiement.
