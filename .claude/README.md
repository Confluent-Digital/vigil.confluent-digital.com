# `.claude/` — Configuration projet pour Claude Code

Config du projet **Vigil** (PHP 8.3 / Slim 4 / MariaDB 10.11 / Redis / Twig /
Bootstrap 5.3), adaptee de celle de `service.comparer-changer.fr`.

## Layout

- `settings.json` — pre-autorise les commandes courantes (docker exec sur les
  conteneurs `vigil_*`, composer, git lecture, curl local sur le port 388) et
  bloque les destructives (`rm -rf src/vendor/git/logs/database/.docker`,
  `docker compose down -v`, `DROP DATABASE`, `TRUNCATE`, `git push --force`,
  `git reset --hard`).
- `settings.local.json` — preferences personnelles, non versionnees.
- `rules/` — regles d'architecture segmentees par sujet. Lire celle qui concerne
  la tache, pas toutes.
- `skills/` — skills declenchables, un `SKILL.md` par sous-dossier.
- `agents/` — sous-agents specialises, invoques via
  `Agent(subagent_type: "<name>")`.
- `commands/` — slash commands projet.
- `hooks/` — scripts declenches par le harness, enregistres dans `settings.json`.
- `statusline.sh` / `statusline.mjs` — repertoire · branche · modele · lignes
  +/- · cout de session.

## Slash commands

| Commande | Effet |
|---|---|
| `/migrate <verbe_objet_table>` | Cree une migration Phinx conforme aux conventions (`t_*`, `prefix_*`, PK `<prefix>_id`, FK indexee, enum + default, pas de logique metier), l'applique, verifie `phinx status` **et** l'existence reelle de l'objet en base. |
| `/predeploy` | Deroule la checklist de `rules/deploy.md` : syntaxe, tests, migrations, partitions, chemin chaud (302, idempotence, mesure `ab`), cache campagnes, secrets. Conclut PRET / PAS PRET. |

## Hooks

| Hook | Evenement | Role |
|---|---|---|
| `php-lint.sh` | PostToolUse(Edit/Write) | `php -l` sur le `.php` edite ; **bloque** (exit 2) en cas d'erreur de syntaxe. |
| `twig-cache-clear.sh` | PostToolUse(Edit/Write) | Vide `cache/twig/*` des qu'un `.twig` est edite — le piege « ma modif n'apparait pas ». |
| `php-lint-stop.sh` | Stop | `php -l` sur tous les `.php` modifies vs `main` avant de conclure ; bloque si parse error (garde anti-boucle `stop_hook_active`). |
| `desktop-notify.sh` | Notification + Stop | Ping desktop quand Claude attend une autorisation ou termine. No-op sans session graphique. |

## Skills

| Skill | Declenche sur |
|---|---|
| `hot-path-change` | Toucher `public/c.php`, `public/pb.php` ou `src/Core/`. Force les sept invariants de latence et de fiabilite, et la mesure `ab` avant/apres. |
| `postback-integration` | Brancher une plateforme externe comme destination de relai. Template a macros, motif de succes, test en simulation avant tout envoi reel. |
| `twig-datatable-page` | Ajouter ou modifier une page du back-office. Conventions DataTables, selectpicker, lecture des chiffres, purge du cache Twig. |

## Agents

| Agent | Role |
|---|---|
| `php-code-reviewer` | Revue statique du `git diff --staged`. Verifie `302` vs `301`, idempotence des conversions, absence de framework et d'appel HTTP sur le chemin chaud, encodage des macros, injection SQL, secrets, `hash_equals`, `\|raw` Twig, invalidation du cache campagnes, conventions de schema. Verdict VALIDE / REJETE avec `fichier:ligne`. Ne modifie rien. |
| `dev-verifier` | Verification **runtime** apres un dev : boot HTTP, migrations + drift, partitions, code `302` reel, double postback -> une seule conversion, PHPUnit cible, preuve metier par test DB **transactionnel (ROLLBACK)**. Verdict OK / KO. **N'envoie jamais de postback reel a un tiers.** |
| `debugger` | Debogueur chirurgical : reproduit, isole la cause racine (`fichier:ligne`), applique le correctif **minimal** et le prouve (test rouge -> vert). Connait les pieges du projet (301 en cache, cache campagnes, partition manquante, backoff, UTC). Ne refactore pas. |
| `ux-designer` | Designer d'interface. **Modifie** les templates Twig et le CSS — jamais les contrôleurs, le SQL ni les routes. Hiérarchie visuelle, densité, chiffres tabulaires, états vides, mode sombre, accessibilité. Porte les règles de lecture des chiffres de `frontend.md` : « vide » ne se lit pas « zéro », un taux affiche son dénominateur, un total porte sur le jeu filtré. Vérifie le rendu réel des écrans, ne le suppose pas. |
| `security-auditor` | Audit **read-only** : forge de conversions (postback sans authentification), open redirect sur la destination, SSRF via les URLs de relai, injection SQL, IDOR publisher, `\|raw`, fuite de cle d'API dans les logs, RGPD sur IP et user-agent. Classe par severite avec scenario d'exploitation. Ne modifie rien. |

L'architecture detaillee vit dans `CLAUDE.md` a la racine et `rules/*.md`. Les
skills et agents supposent ce contexte.
