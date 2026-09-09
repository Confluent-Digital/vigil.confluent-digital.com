---
description: Mise en production — checklist, ordre des operations, pieges
---

# Deploiement

## Ordre des operations

1. `git pull`
2. `composer install --no-dev --optimize-autoloader`
3. `vendor/bin/phinx migrate`
4. `rm -rf cache/twig/*` — **obligatoire** : `auto_reload = false` en production
5. `docker compose restart vigil_php` (vide OPcache)
6. Smoke tests (`testing.md`)

## Checklist avant de pousser

- [ ] `php -l` propre sur tous les fichiers modifies (le hook `Stop` le verifie)
- [ ] PHPUnit vert
- [ ] Migration jouee **et** `phinx status` a `up` en local
- [ ] Si le chemin chaud (`c.php`, `pb.php`, `Core/`) est touche : mesure `ab`
      avant/apres, et un `curl -I` prouvant le `302`
- [ ] Si une campagne, un acces ou une destination change de structure :
      `CampaignCache::invalidate()` est bien appele
- [ ] Aucun secret en dur (tout dans `.env`)

## Premiere mise en production — ce qui casse en silence

Trois choses ne produisent **aucune erreur** quand elles manquent, et donnent
toutes la meme impression : « ca ne marche pas ».

1. **Le cron n'est pas installe.** `PostbackFlushTask` ne tourne pas, donc
   AUCUN relai ne part. Les conversions s'enregistrent, la file se remplit,
   et rien ne remonte chez le partenaire.
   `crontab -u www-data config/cron` — puis verifier une premiere execution.

2. **Le money site n'envoie pas `&s=<secret>`.** La conversion est refusee,
   mais `/pb` rend `200` : le branchement a l'air reussi. C'est la cause la plus
   frequente d'un premier branchement, et elle est desormais visible sur
   `/app/dead-links` (section « Postbacks refuses »).

3. **Le cache Twig n'est plus sur le volume monte.** Il l'etait, et cela
   produisait « Unable to create the cache directory » des la premiere page en
   production : le conteneur tourne sous l'UID du `.env`, l'hote sous un autre,
   et un `chown` ne suffit pas quand les deux ne coincident pas. Il vit
   desormais dans `/tmp/vigil-twig`, a l'interieur du conteneur —
   `TWIG_CACHE_DIR` permet de le deplacer. Si le repertoire est inaccessible,
   l'application renonce au cache et le journalise, plutot que de tomber : une
   application lente vaut mieux qu'une application morte.

4. **`APP_URL` pointe ailleurs que le domaine reel.** Les liens de tracking
   affiches dans le back-office menent dans le vide. `init.sh` interroge
   `$APP_URL/health` et refuse de conclure au vert.

## Checklist de premiere mise en production

- [ ] `.env` : `APP_ENV=production`, `APP_URL=https://…`, `APP_SECRET`,
      `POSTBACK_SECRET`, identifiants base, `MAIL_*` reels
- [ ] `APP_ENV=production` fait **disparaitre** le bac a sable — les routes ne
      sont plus enregistrees. Le test en production se fait donc sur de vraies
      campagnes.
- [ ] `composer install --no-dev --optimize-autoloader`
- [ ] `vendor/bin/phinx migrate`
- [ ] `php bin/task_runner.php Maintenance PartitionMaintenanceTask ensure`
      — sans partition, l'ingestion s'arrete au changement de mois
- [ ] `crontab -u www-data config/cron`, puis verifier que
      `PostbackFlushTask` s'execute
- [ ] ~~`rm -rf cache/twig/*`~~ — plus necessaire : le cache Twig vit
      **dans le conteneur** (`/tmp/vigil-twig`), pas sur le volume monte.
      Redemarrer `vigil_php` le purge.
- [ ] Changer le mot de passe du compte administrateur cree par le seed
- [ ] Un parcours reel : lien -> money site -> postback -> relai, en verifiant
      `/app/queue` (code HTTP et corps de reponse) et `/app/dead-links`

## Zones sensibles

Une modification de ces surfaces demande une verification runtime, pas
seulement une revue statique :

- `public/c.php` et `public/pb.php` — le trafic et l'argent passent par la
- `src/Core/MacroEngine.php` — une macro mal encodee casse toutes les
  destinations d'un coup
- `t_conversion` et sa contrainte UNIQUE — la perdre autorise les doublons, et
  les doublons partent en S2S chez le partenaire
- `PostbackFlushTask` — un bug ici rejoue ou perd des conversions

## APP_URL — la valeur qui casse en silence

`APP_URL` prefixe les liens de tracking affiches dans le back-office. Ce n'est
**pas** l'adresse consultee : un lien de tracking est un objet public qu'un
publisher colle dans ses e-mails, le deduire du navigateur ferait copier
`http://127.0.0.1:388/c/...` a un administrateur.

| Environnement | Valeur |
|---|---|
| developpement | `http://dev.vigil.confluent-digital.com` — convention du parc, entree `/etc/hosts` vers 127.0.0.1, **HTTP** (pas de certificat) |
| production | `https://vigil.confluent-digital.com` |

Une valeur pointant vers un domaine hors ligne produit des liens sur lesquels
personne ne peut cliquer, **sans aucune erreur visible**. Deux garde-fous :

- `init.sh` interroge `$APP_URL/health` et refuse de conclure au vert si ca ne
  repond pas ;
- le back-office affiche un avertissement quand `APP_URL` ne correspond pas au
  domaine consulte, avec la valeur a poser.

## Pieges

- **Ne jamais deployer une migration qui supprime la contrainte UNIQUE de
  `t_conversion`**, meme temporairement. Les doublons crees pendant la fenetre
  seront relayes chez les partenaires et ne se rattrapent pas.
- Une partition manquante sur `t_click` arrete l'ingestion **en silence**.
  Verifier `PartitionMaintenanceTask` apres tout deploiement de fin de mois.
- Redemarrer PHP-FPM vide APCu : le cache campagnes se reconstruit au premier
  clic. C'est voulu, mais cela signifie que le tout premier clic apres un
  deploiement fait un SELECT.
