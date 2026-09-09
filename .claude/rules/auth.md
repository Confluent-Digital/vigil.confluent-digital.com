---
description: Connexion en deux temps — TOTP, codes de secours, repli par courriel, plafonnement
paths:
  - "src/Core/{Auth,Totp,TwoFactor,LoginGuard,Crypto,Base32,Mailer}.php"
  - "src/Modules/Auth/**"
  - "src/Modules/Account/**"
---

# Authentification

Le back-office permet de modifier l'URL de destination d'une campagne — donc de
detourner tout le trafic — et de lire les secrets de postback. Le mot de passe
seul n'y donne pas acces.

## Le flux

```
mot de passe  ->  session EN ATTENTE   (Auth::check() reste FAUX)
              ->  enrolement si le compte n'a pas d'application
              ->  second facteur       (TOTP | code courriel | code de secours)
              ->  session complete     (Auth::check() devient VRAI)
```

**`Auth::attemptPassword()` n'authentifie personne.** Elle valide le mot de
passe et pose `$_SESSION['pending']`. Seul `Auth::completeLogin()` pose
`$_SESSION['user']`, et c'est ce dernier que lit `AuthMiddleware`. Toute
modification de ce chemin doit preserver cette separation : les confondre
rendrait le second facteur decoratif.

L'etape en attente **expire au bout de dix minutes** : une session a demi
ouverte, laissee sur un poste partage, ne doit pas rester exploitable.
L'identifiant de session est regenere **deux fois**, au mot de passe puis a
l'authentification complete.

## Obligatoire pour tout le monde

Y compris les comptes `publisher` : ils voient les conversions et les payouts de
leur perimetre, ce sont des donnees commerciales. Un compte non enrole est
envoye sur `/login/enroll`, sans possibilite de sauter l'etape.

## TOTP

RFC 6238, HMAC-SHA1, 6 chiffres, pas de 30 s, fenetre de +/- 1 pas.
`tests` : l'implementation est verifiee contre les six vecteurs officiels de
l'annexe B de la RFC — c'est ce qui garantit qu'une vraie application
d'authentification acceptera nos codes.

- **Le secret est chiffre au repos** (`Crypto`, sodium secretbox, cle derivee
  d'`APP_SECRET`). En clair, une lecture SQL suffirait a fabriquer des codes
  valides pour n'importe quel compte : le second facteur ne protegerait plus de
  rien face a un dump egare.
- **`user_totp_last_step` est obligatoire.** Un code TOTP reste valide 30 s ;
  sans enregistrer le pas consomme et refuser tout pas inferieur ou egal, le
  code est rejouable pendant toute sa fenetre. `Totp::verify()` prend ce dernier
  pas en argument et l'appelant **doit** le persister.
- Le secret d'enrolement vit **en session** tant qu'il n'est pas confirme par un
  code. L'ecrire en base avant la preuve verrouillerait un compte dont le scan a
  echoue.
- Changer `APP_SECRET` rend tous les secrets illisibles et force un
  re-enrolement general. C'est voulu : une rotation de cle doit etre un acte
  conscient.

## Repli par courriel — le maillon faible, assume

Un lien « recevoir un code par courriel » est disponible en permanence sur
l'ecran de verification. **Ce choix ramene la securite de l'ensemble a celle de
la boite mail** : quiconque y a acces peut se connecter sans l'application. Il a
ete retenu en connaissance de cause, pour le confort d'usage.

Les garde-fous qui en limitent la portee — ne pas les retirer :

| Garde-fou | Pourquoi |
|---|---|
| Envoi **uniquement** a l'adresse du compte | une adresse fournie dans la requete ferait de ce repli un moyen de detourner n'importe quel compte |
| Code hashe en base | un dump ne doit pas livrer un code encore valide |
| Usage unique, 10 minutes | reduit la fenetre d'interception |
| 5 tentatives par code, puis invalidation | six chiffres se devinent en un million d'essais |
| Les codes precedents sont invalides a chaque envoi | deux codes valides simultanement doublent les chances d'un tirage |
| 5 envois par heure et par compte | borne la cadence de sollicitation |
| **Notification a l'utilisateur a chaque emploi** | un contournement du TOTP ne doit jamais etre silencieux — c'est ce qui permet de detecter une boite compromise |
| Compteur visible sur `/app/security` | rend l'usage du repli lisible dans le temps |

## Codes de secours

Huit codes a usage unique, generes a l'enrolement, **affiches une seule fois**
et stockes hashes : ni l'utilisateur ni un administrateur ne peuvent les relire.
Regenerables depuis `/app/security` ; la regeneration invalide les anciens.

Ils sont testes **en dernier** dans `verify()`, et seulement si la saisie
contient un tiret : on ne veut pas en consommer un pour une faute de frappe sur
un code TOTP.

## Plafonnement et journal

`LoginGuard` sert deux buts a la fois : ralentir le devinement et laisser une
trace.

- 10 echecs par couple (compte, IP) sur 15 minutes -> blocage temporaire.
- `t_login_attempt` journalise chaque evenement, y compris les succes. C'est la
  source de l'ecran `/app/security`.
- Les echecs sont effaces apres une connexion aboutie.

## Ne jamais toucher a `t_user` pour nettoyer

**Le secret TOTP d'un compte reel a ete detruit une fois, en nettoyant des
donnees de test par `UPDATE t_user SET user_totp_secret = NULL`.** Il n'etait
pas recuperable : chiffre au repos, absent de toute sauvegarde, et le journal
binaire de MariaDB est desactive (`log_bin OFF`). La personne a du rescanner un
QR code, et son ancienne entree est restee dans son application
d'authentification, indistinguable de la nouvelle.

Regles qui en decoulent :

- **Un nettoyage de donnees de test ne touche jamais `t_user`**, ni
  `t_user_recovery_code`, ni `t_user_email_code`. Ces tables portent l'acces des
  personnes, pas des donnees d'essai.
- **Pour tester une connexion, ne pas emprunter un compte reel.** Les tests
  automatises creent leurs propres lignes prefixees `PHPUNIT-`. Pour un essai
  manuel, le bac a sable fournit un **compte jetable** : « Creer le jeu de
  test » affiche une fois un couple e-mail / mot de passe tire au hasard, et
  `Fixtures::destroy()` le supprime. Il n'y a donc plus aucune raison de
  toucher au compte de quelqu'un.

  Ce garde-fou existe parce que l'enrolement d'un compte reel a ete ecrase
  **quatre fois** en une seule session, par des essais en ligne de commande.
  Chaque fois irrecuperable.
- **Remettre a zero une verification est un acte delibere**, pas du SQL au fil
  de l'eau : `php bin/reset-2fa.php <email> --confirme`. La commande affiche ce
  qu'elle ferait sans le drapeau, ne touche qu'un compte, et laisse une trace
  dans `t_login_attempt` — donc sur l'ecran Securite de la personne concernee.
- **Le libelle du QR porte la date d'enrolement.** Une application
  d'authentification ne sait pas qu'un secret a ete revoque : elle continue
  d'afficher l'ancienne entree sous un nom identique. Sans ce marqueur, deux
  entrees « Vigil : untel@… » cohabitent sans qu'on puisse dire laquelle est
  vivante, et l'ancienne rend des codes refuses.

## Un secret absent ne vaut PAS « pas de second facteur »

`isEnrolled()` repond non dans deux situations opposees :

| Situation | Ce qu'il faut faire |
|---|---|
| compte neuf, jamais enrole | proposer l'enrolement |
| compte enrole **puis prive de son secret** | exiger un code de secours |

On ne faisait pas la difference, et les deux menaient a `/login/enroll`. Deux
consequences, dont une grave :

1. **Quiconque detenait le mot de passe pouvait enroler SON appareil.** Un
   secret efface revenait donc a desactiver le second facteur au profit du
   premier arrivant — l'inverse exact de ce qu'il promet. Et un secret s'efface :
   c'est arrive quatre fois.
2. La personne legitime ne se voyait **jamais** proposer ses codes de secours,
   pourtant intacts en base. Elle reconfigurait alors qu'elle avait de quoi
   entrer, et accumulait une entree morte de plus dans son application.

`AuthController::secondFactorPath()` tranche desormais sur les codes de secours
restants : tant qu'il en reste un, on passe par `/login/verify`, qui les accepte
au meme titre que le code recu par courriel. Le garde-fou est pose sur le GET
**et** sur le POST d'enrolement — sinon un POST direct le contournerait.

L'ecran de verification dit alors explicitement qu'il attend un code de secours,
et combien il en reste. Sans cela on saisit indefiniment un code a six chiffres
que plus rien ne produit.

Verrouille par
`EnrollmentTest::testSansSecretMaisAvecCodesDeSecoursOnNePeutPasEnroler`.

**Consequence pratique** : ne jamais supprimer les lignes de
`t_user_recovery_code` en meme temps que le secret. Ce sont elles qui
distinguent « compte neuf » de « compte a recuperer », et elles seules
permettent de rentrer.

## Gerer les comptes : `bin/user.php`, pas du SQL

```bash
php bin/user.php list                          # qui a acces, et qui a configure son second facteur
php bin/user.php create <email> [role]         # admin | manager | publisher
php bin/user.php password <email>              # reinitialise, affiche une fois
php bin/user.php email <ancien> <nouveau>      # renomme
php bin/user.php disable|enable <email>
```

**Un mot de passe ne se retrouve pas.** Il est hache en bcrypt (cout 12) : il
n'existe nulle part en clair, pas plus pour un administrateur que pour un
attaquant qui lirait un dump. La seule operation possible est la
reinitialisation, et le nouveau mot de passe s'affiche une seule fois.

**Changer l'adresse ne casse pas la verification en deux etapes.** Le secret
TOTP est rattache a `user_id`, pas a `user_email` : apres un renommage,
l'application d'authentification continue de rendre des codes valides. Elle
gardera l'ancienne adresse dans son libelle, sans consequence. Reconfigurer
« pour mettre le libelle a jour » detruirait un enrolement valide — c'est
exactement l'erreur qui a coute quatre enrolements.

De meme, `password` **ne touche pas** au second facteur : les deux facteurs sont
independants, c'est le principe.

**Ne pas creer de compte par `INSERT` a la main** : le hachage, le role et les
contraintes d'unicite y sont faciles a manquer, et un `user_password` mal forme
donne un compte qui refuse toutes les connexions sans dire pourquoi.

## `ADMIN_EMAIL` doit designer une boite reelle

`AdminUserSeeder` lit `ADMIN_EMAIL` et retombe sur
`admin@confluent-digital.com`. Cette variable est restee absente de
`.env.example` pendant toute l'installation : la premiere mise en production
s'est donc faite sur une adresse **qui n'existe pas**.

Ce n'est pas cosmetique. `TwoFactor` envoie le code de repli a `user_email` :
une adresse fictive ferme silencieusement le seul chemin de secours en cas de
perte du telephone, et on ne s'en apercoit que le jour ou on en a besoin. Le
meme envoi porte l'avertissement « repli utilise », qui est la seule alerte
qu'une connexion s'est faite sans le second facteur.

Toute variable lue par le code doit figurer dans `.env.example`, meme quand
elle a un defaut : un defaut non documente n'est pas un choix, c'est un
accident qui attend.

## Pieges

- **Un handler de route Slim ne prend pas de parametre supplementaire.** Slim
  passe toujours les arguments de route en troisieme position : un
  `?string $erreur = null` y recoit un tableau, et la page rend 500 sur un
  `TypeError`. Le rendu doit vivre dans une methode privee que le handler
  appelle — cf. `AuthController::renderEnroll()`.
- **Un code refuse doit se VOIR.** L'ecran d'enrolement a longtemps rendu un
  `{% if %}` vide : la page se rechargeait a l'identique et l'utilisateur
  n'avait aucun moyen de savoir pourquoi. Verrouille par
  `tests/Http/EnrollmentTest`.
- **Ne jamais reutiliser un placeholder nomme** dans une requete preparee :
  `PDO::ATTR_EMULATE_PREPARES` est a `false`, et PDO rend alors
  `HY093 Invalid parameter number`. Rencontre sur `LoginGuard::isLocked()`.
- **PHPMailer refuse un nom d'hote contenant un tiret bas** — ce n'est pas un
  caractere valide dans un hostname. Toute la convention de nommage des
  conteneurs du parc en utilise : d'ou l'alias reseau `mailhog` dans
  `docker-compose.yml`. Ne pas remettre `MAIL_HOST=vigil_mailhog`.
- **`MAIL_ENCRYPTION` vide desactive `SMTPAutoTLS`** : MailHog ne parle pas TLS,
  et sans cette bascule PHPMailer tente STARTTLS et l'envoi echoue en
  developpement.
- Le message d'erreur de connexion est **unique** (« Identifiants invalides »).
  Distinguer « e-mail inconnu » de « mot de passe faux » donne un oracle pour
  enumerer les comptes. `password_verify` est appele meme sans utilisateur
  trouve, sur un hash factice, pour que le temps de reponse ne trahisse rien.
- Le QR code est genere **cote serveur** (BaconQrCode, SVG inline). Passer par
  une API de generation d'images divulguerait le secret TOTP.
- Le QR reste sur fond blanc dans les deux themes : un lecteur attend un
  contraste sombre-sur-clair, l'inverser le rend illisible.
