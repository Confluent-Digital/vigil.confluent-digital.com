---
description: Bac a sable — money site et plateforme externe simules, absents en production
paths:
  - "src/Core/Sandbox.php"
  - "src/Modules/Sandbox/**"
  - "src/Views/pages/sandbox/**"
---

# Bac a sable

`/sandbox` fait tourner la boucle complete sans toucher a un tiers : jeu de
demonstration autonome, faux money site qui affiche ce qu'il a recu, fausse
plateforme externe qui recoit le relai, et un journal des cinq etapes.

## L'invariant

**Il n'existe pas en production.** Une route capable de declencher un postback
est un outil de forge de conversions avec une interface conviviale : qui la
trouve credite des conversions qui partiront chez les partenaires et se
factureront.

On ne la protege donc pas — on ne l'enregistre pas. `src/routes.php` teste
`Sandbox::isEnabled()` avant d'appeler `$app->group('/sandbox', ...)`. Une route
qui n'existe pas ne se contourne pas : il n'y a ni verification d'autorisation a
oublier, ni bogue de session a exploiter.

Environnements ou il est monte : `dev`, `development`, `local`, `test`.
Verrouille par `tests/Feature/SandboxGuardTest` — qui construit reellement le
routeur en `production` et exige zero chemin `/sandbox`.

## `/lp` — la page d'atterrissage, elle, existe en production

Le bac a sable disparaissant en production, il n'y avait aucun moyen d'y
brancher une campagne de test sans posseder un vrai money site. `/lp` comble ce
manque **sans rouvrir le trou**.

La difference tient en une phrase : **le faux money site du bac a sable APPELLE
`/pb`, la page `/lp` ne fait que REFLETER.**

| | bac a sable | `/lp` |
|---|---|---|
| appelle `/pb` | oui, cote serveur | jamais |
| lit un secret en base | oui | jamais |
| ecrit en base | oui (jeu de test, trafic) | jamais |
| interroge la base | oui | **meme pas** |
| existe en production | non | oui |

Elle n'interroge pas la base, pas meme pour savoir si le clic existe : ce serait
un oracle sur la validite d'un identifiant. `/app/clicks` repond deja a la
question, derriere authentification.

Elle n'affiche donc que ce que l'appelant vient lui-meme d'envoyer — sauf `s` et
`secret`, masques : une page de test se montre en capture d'ecran, se partage en
visio, et reste dans un historique. Leur seule presence est signalee comme une
erreur de branchement, puisque ce sont des parametres de postback.

Le postback reste a declencher a la main : la page prepare l'URL avec un
**emplacement** pour le secret. C'est ce qui separe un outil de test d'un bouton
« creer une conversion ».

Verrouille par `tests/Http/LandingTest` : le secret ne ressort jamais, aucun
formulaire de declenchement, ouvrir la page ne cree aucune conversion, et les
deux erreurs de branchement les plus probables sont nommees (`{clickid}` absent
de la destination, ou `{external_clickid}` mis a sa place).

## Trois regles de conception

1. **La destination de relai pointe vers nous.** `/sandbox/platform`, jamais une
   vraie plateforme : un relai parti pour de vrai polluerait le reporting d'un
   partenaire et ne se retirerait pas.
2. **Le jeu de demonstration est autonome** — son propre client `[BAC A SABLE]`,
   son publisher, sa campagne. Rien n'est greffe sur des donnees reelles, donc
   rien ne pollue les statistiques.
3. **Le faux money site appelle `/pb` en HTTP, cote serveur**, comme le ferait un
   vrai. Un appel de fonction ne traverserait ni nginx, ni PHP-FPM, ni les
   en-tetes : on ne testerait pas grand-chose.

## Deux adresses, deux publics — ne jamais les confondre

| Adresse | Joignable depuis | Sert a |
|---|---|---|
| `vigil_nginx` (`TEST_BASE_URL`) | les conteneurs uniquement | ce que le **serveur** appelle : le faux money site vers `/pb`, le worker vers `/sandbox/platform` |
| `APP_URL` (`http://dev.vigil…`) | le navigateur uniquement | ce que le **visiteur** doit atteindre : la destination d'une campagne |

La symetrie est exacte : ni l'une ni l'autre n'est joignable des deux cotes.
`Sandbox::internalBaseUrl()` et `Sandbox::publicBaseUrl()` existent pour rendre
le choix explicite.

Le defaut rencontre : la destination de campagne portait l'adresse interne, et
la redirection envoyait le navigateur sur `http://vigil_nginx/sandbox/…` — un
nom qui ne resout pas hors des conteneurs. Le lien semblait fonctionner (302
correct, macros resolues) mais menait nulle part. Verrouille par
`SandboxFixturesTest::testLaDestinationEstPubliqueEtLesRelaisInternes`.

## Deux pieges rencontres

- **`/sandbox/platform` est hors du groupe authentifie.** C'est le *worker* qui
  l'appelle, en HTTP interne et sans cookie : derriere `AuthMiddleware` elle
  rendait un `302` vers la connexion, que le worker comptait — a juste titre —
  comme un echec. Ce n'est pas une ouverture : la route entiere n'existe pas en
  production.
- **Le journal vit dans un fichier, pas en session.** Pour la meme raison : la
  trace du worker atterrissait dans une session vide, et l'etape la plus
  interessante de la boucle etait justement celle qu'on ne voyait pas.
  `logs/sandbox-journal.json`, ecrit avec `LOCK_EX`, gitignore.

## Le parcours complet — les deux bouts

Le bac a sable demarrait au lien Vigil : on voyait la plateforme externe
RECEVOIR le relai, jamais l'EMETTRE. Or c'est tout l'interet du produit — la
plateforme qui a envoye le visiteur recupere SON identifiant a la fin. Sans les
deux bouts, la boucle ne se referme pas, et le mecanisme reste invisible.

`/sandbox/plateforme?token=<jeton>` joue le role de la plateforme Confluent
Digital : elle fabrique son identifiant (`CD-XXXXXXXX`), l'affiche en grand,
et envoie le visiteur vers Vigil en le passant dans `cid`.

```
1. Plateforme CD   emet CD-KSGJY92K
2. Vigil           garde CD-KSGJY92K, redirige avec 01M22JH2FQQ… (son ULID)
3. Money site      formulaire pre-rempli, le visiteur valide
4. Vigil           postback -> conversion -> relais empiles
5. Plateforme CD   son pixel recoit CD-KSGJY92K
```

L'ecran d'accueil raconte ces cinq etapes, et le journal **rapproche
explicitement** l'identifiant emis de celui recu : « La plateforme a recu SON
identifiant de depart — la boucle est fermee ». C'est la seule chose a regarder,
et elle ne saute pas aux yeux dans un journal brut.

Verrouille par `tests/Http/RoundTripTest` : l'aller-retour d'un identifiant, et
le cas d'origine — deux campagnes, un seul champ de postback chez le money site,
chacune recupere le sien.

## Le jeu de test

`App\Modules\Sandbox\Fixtures` — trois operations, trois boutons :

| Bouton | Effet |
|---|---|
| **Creer le jeu de test** | 3 clients, 4 publishers, 5 campagnes, 15 acces, 5 destinations de campagne, 3 pixels publisher |
| **Generer du trafic** | ~220 clics repartis sur 7 jours, ~9 % de conversions, statuts realistes, puis recalcul des statistiques |
| **Tout supprimer** | retour exact a l'etat d'avant |

Trois choix de conception :

- **La matrice d'acces est volontairement incomplete** — 15 couples sur 20.
  C'est ce qui rend visible que l'autorisation n'est pas un controle mais
  l'absence de jeton.
- **Un publisher sur quatre n'a pas de pixel**, pour qu'on voie la difference
  entre une conversion remontee et une conversion seulement enregistree.
- **Le trafic est ecrit directement en base**, sans passer par `/c`. Le but est
  de remplir les ecrans, pas d'exercer le chemin chaud — c'est le bouton
  « Cliquer » de chaque ligne qui fait cela, fidelement. Generer deux cents
  clics par HTTP prendrait une minute pour le meme resultat visuel.

## La suppression se fie au prefixe, et a rien d'autre

**Tout ce que le bac a sable cree porte `Sandbox::PREFIX`** (`[BAC A SABLE]`).
`Fixtures::destroy()` supprime exactement ce qui le porte : creer quoi que ce
soit sans lui, c'est laisser une donnee d'essai se melanger aux chiffres reels.

Le cas particulier a ne pas oublier : **les pixels de portee publisher n'ont pas
de campagne**, ils survivent donc a la boucle qui nettoie campagne par campagne.
Ils sont retires explicitement, par leur publisher.

Verrouille par `tests/Feature/SandboxFixturesTest` : le jeu cree ce qu'il
annonce, la matrice est incomplete, trois pixels sur quatre publishers, le
trafic remplit les tables, **la suppression rend chaque compteur a sa valeur
d'avant**, et un temoin sans prefixe survit intact.

## Ce qu'on peut exercer

| Manipulation | Ce qu'elle montre |
|---|---|
| Cliquer sur le lien fourni | le `302`, les macros resolues, les douze champs transmis |
| Ouvrir `/app/clicks` apres | que le pre-remplissage **n'est pas** persiste |
| Valider deux fois le meme `txid` | l'idempotence : une seule conversion |
| Changer le secret de postback | le refus, avec un `200` rendu quand meme |
| Vider le `txid` | le mode degrade : une conversion par clic |
| Passer le statut a `chargeback` | la mise a jour en place et le second relai |
| « Vider la file maintenant » | le relai part, et la fausse plateforme recoit son propre `cid` |
