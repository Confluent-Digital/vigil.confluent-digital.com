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

## Cache Twig

`cache/twig/` est vide automatiquement par le hook `twig-cache-clear.sh` a
chaque edition d'un `.twig`. En production, `auto_reload = false` : le cache
**doit** etre purge au deploiement, sinon une modification de template n'apparait
jamais.
