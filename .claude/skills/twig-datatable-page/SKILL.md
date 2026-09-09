---
name: twig-datatable-page
description: Ajouter ou modifier une page du back-office Vigil (Slim 4 + Twig + Bootstrap 5.3 + DataTables). Surface les conventions de frontend.md — filtres select en tfoot, data-order sur les colonnes numeriques, ligne TOTAL sur le jeu filtre, selectpicker destroy/empty, server-side sur les tables volumineuses, dark mode, purge du cache Twig.
---

# Page back-office avec DataTable

## Squelette

- Controleur sous `src/Modules/<Nom>/Controllers/`, repository sous
  `src/Modules/<Nom>/Repositories/`.
- Route dans `src/routes.php`, groupe `/app`, derriere le middleware
  d'authentification.
- Template sous `src/Views/pages/<module>/`, etendant
  `layouts/base.html.twig`.

## Conventions a respecter

- Filtre **select** en `tfoot` sur les colonnes texte, pas un champ libre.
- `data-order` avec la valeur brute sur toute colonne numerique formatee, sinon
  le tri est lexicographique et `1 200 €` passe avant `900 €`.
- Ligne **TOTAL** en `tfoot`, calculee sur le **jeu filtre** et non sur la page
  courante.
- **Server-side obligatoire** sur les listes de clics et de conversions : ces
  tables se comptent en millions.
- Les stats se lisent dans `t_stats_hourly`, jamais dans `t_click` ni
  `t_conversion`.
- `selectpicker` : `destroy().empty()` puis `selectpicker()`, jamais
  `refresh()`. Delegation d'evenement sur `document` pour survivre aux destroy.
- Dates stockees en UTC, **affichees en `Europe/Paris`**, avec le fuseau visible
  dans l'en-tete de l'ecran.
- « Vide » ne se lit pas « zero » : `0` pour zero clic, `—` pour une donnee pas
  encore agregee.

## Apres

```bash
rm -rf cache/twig/*
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:388/app/<route>
```

Le hook `twig-cache-clear.sh` purge deja le cache a chaque edition d'un `.twig`,
mais le verifier evite de debugger une page qui n'a pas bouge.
