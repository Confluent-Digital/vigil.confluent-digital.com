---
description: Verifications avant mise en production — chemin chaud, migrations, tests, checklist deploy
---

Deroule la checklist de `.claude/rules/deploy.md` sur l'etat courant.

1. **Perimetre** : `git diff --name-only $(git merge-base HEAD main)` + staged.
2. **Syntaxe** : `php -l` sur chaque `.php` modifie.
3. **Tests** : `docker exec vigil_php vendor/bin/phpunit`. Les trois suites
   doivent etre vertes. Un `skipped` sur la suite `http` signifie que le serveur
   ne repondait pas — ce n'est pas un succes, relance-la.
4. **Migrations** : `phinx status` (aucune en attente), et pour toute colonne
   nouvellement referencee, verification dans `information_schema` qu'elle existe.
5. **Partitions** : la partition du mois courant **et** celle du mois suivant
   existent sur `t_click`.
6. **Chemin chaud** — si `public/c.php`, `public/pb.php` ou `src/Core/` est
   touche :
   - `curl -I http://127.0.0.1:388/c/<token>` -> **302**, `Cache-Control: no-store` ;
   - deux `/pb` identiques -> une seule conversion, un seul relai ;
   - mesure `ab -n 5000 -c 50` comparee a la precedente.
7. **Cache campagnes** : si une structure de campagne, d'acces ou de destination
   change, `CampaignCache::invalidate()` doit apparaitre dans le diff.
8. **Secrets** : aucun secret en dur, `.env` non suivi par git.

Termine par un tableau ✅/❌ par point et une conclusion **PRET** / **PAS PRET**.
Un point saute est dit explicitement — il ne compte pas comme vert.
