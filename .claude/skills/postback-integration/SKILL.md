---
name: postback-integration
description: Brancher une nouvelle plateforme externe comme destination de relai S2S dans Vigil (t_campaign_postback). Couvre le template d'URL a macros, la methode et le corps, le motif de succes, les statuts declencheurs, le test en simulation et le rejeu. A utiliser quand on ajoute ou modifie un relai vers un tracker, un pixel ou un webhook.
---

# Brancher une plateforme externe

## La regle

**Brancher une plateforme ne doit demander aucune ligne de code.** Une
destination se decrit entierement par une ligne de `t_campaign_postback` :
un template d'URL, une methode, des macros. Si l'integration ne rentre pas dans
ce modele, c'est `MacroEngine` qu'il faut etendre — jamais un `switch` par
plateforme.

C'est la lecon des six `Conversions*Task` du DMP, une par plateforme, qu'il faut
maintenir en parallele a chaque evolution.

## Ce qu'il faut recuperer aupres de la plateforme

1. L'**URL de postback** attendue, avec ses parametres exacts.
2. Le nom du parametre qui porte **leur** click id — c'est lui qui doit recevoir
   `{external_clickid}`.
3. Le format du montant : centimes ou unites, separateur decimal.
4. Ce que la plateforme considere comme un succes : code HTTP seul, ou corps de
   reponse (certaines repondent `200` avec `{"status":"error"}`).
5. Si elle accepte les **changements de statut** (annulation, chargeback).

## Configurer la destination

| Colonne | A remplir |
|---|---|
| `cpb_url` | `https://tracker.ext/pb?cid={external_clickid}&sum={payout}&status={status}` |
| `cpb_method` | `GET` le plus souvent ; `POST` pour les APIs JSON |
| `cpb_body` / `cpb_headers` | corps JSON et en-tetes pour les APIs (CAPI, webhooks) |
| `cpb_on_status` | `approved` par defaut ; ajouter `chargeback` si la plateforme sait l'absorber |
| `cpb_id_publisher` | `NULL` = toutes les sources ; renseigner pour un relai cible |
| `cpb_success_pattern` | regex sur le corps ; vide = seul le code HTTP compte |

Macros : `{clickid}` `{external_clickid}` `{campaign_id}` `{publisher_id}`
`{publisher_token}` `{sub1}`..`{sub5}` `{payout}` `{txid}` `{status}`
`{timestamp}` `{ip}` `{country}`.

Toute valeur est `rawurlencode`ee par `MacroEngine`. Ne jamais concatener a la
main.

## Tester

1. **En simulation d'abord** : l'ecran de test forge un `/pb` et affiche l'URL
   resolue **sans l'appeler**. Verifie l'URL a l'oeil avant d'envoyer quoi que
   ce soit.
2. Puis un envoi reel sur un clic de test, et la lecture de `pq_http_code` et
   `pq_response` dans le journal des relais.
3. Enfin, confirmation **cote plateforme** que la conversion est arrivee. Un
   `200` ne prouve rien tant que personne ne l'a vue de l'autre cote.

## Pieges

- Une cle d'API dans `cpb_url` se retrouve en clair dans `t_postback_queue` et
  dans les logs. Elle doit etre masquee a l'affichage.
- Une URL de destination interne (`127.0.0.1`, plage privee) est une SSRF :
  `cpb_url` est validee a l'enregistrement.
- Ne jamais tester en visant la plateforme reelle depuis la suite de tests : le
  client HTTP y est double. Un relai parti pour de vrai pollue le reporting du
  partenaire et ne se retire pas.
