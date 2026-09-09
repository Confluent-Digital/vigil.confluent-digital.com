<?php

declare(strict_types=1);

namespace App\Modules\Landing\Controllers;

use App\Core\Prefill;
use App\Core\Ulid;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Fausse page d'atterrissage, pour brancher une campagne de test sur Vigil
 * lui-meme plutot que sur un vrai money site.
 *
 * ── Pourquoi elle peut exister en production, alors que le bac a sable non ──
 *
 * Le bac a sable est interdit en production parce que son faux money site
 * **appelle `/pb` cote serveur**, avec le secret lu en base : c'est un outil de
 * forge de conversions avec une interface conviviale.
 *
 * Cette page-ci ne fait rien de tel. Elle est **purement reflechissante** :
 *
 *   - elle n'appelle jamais `/pb`, ni aucune autre URL ;
 *   - elle ne lit aucun secret, ni en base ni ailleurs ;
 *   - elle n'ecrit rien, nulle part ;
 *   - elle n'interroge meme pas la base pour savoir si le clic existe — ce
 *     serait un oracle, et `/app/clicks` repond deja a la question, derriere
 *     authentification.
 *
 * Elle se contente d'afficher ce que l'appelant vient lui-meme d'envoyer. Elle
 * ne divulgue donc rien qu'il ne possede deja.
 *
 * Le postback, lui, reste a declencher a la main : la page prepare l'URL avec
 * un **emplacement** pour le secret, jamais le secret. C'est ce qui separe un
 * outil de test d'un bouton « creer une conversion ».
 */
final class LandingController
{
    public function __construct(private readonly Twig $view)
    {
    }

    public function show(Request $request, Response $response): Response
    {
        $query   = $request->getQueryParams();
        $clickId = trim((string) ($query['clickid'] ?? $query['click_id'] ?? $query['cid'] ?? ''));

        // Controle de FORME uniquement. Verifier l'existence en base
        // renseignerait un inconnu sur la validite d'un identifiant.
        $formeOk = $clickId !== '' && Ulid::isValid($clickId);

        // Les douze champs du kit mailing sont affiches — l'appelant vient de
        // les envoyer — mais ils ne sont ni journalises ni ecrits. C'est
        // justement ce que cette page sert a verifier de visu.
        $prefill = Prefill::extract($query);

        // `s` et `secret` n'ont rien a faire sur une page d'atterrissage : ce
        // sont des parametres de POSTBACK. S'ils arrivent ici, c'est une erreur
        // de branchement — on le dit, sans reafficher la valeur. L'appelant la
        // connait deja, mais une page de test se montre en capture d'ecran, se
        // partage en visio, et reste dans un historique de navigateur.
        $secretsMasques = false;
        $autres         = [];
        foreach ($query as $cle => $valeur) {
            $cle = (string) $cle;
            if (!is_scalar($valeur)
                || array_key_exists($cle, $prefill)
                || in_array($cle, ['clickid', 'click_id', 'cid'], true)
            ) {
                continue;
            }
            if (in_array(strtolower($cle), ['s', 'secret'], true)) {
                $secretsMasques = true;
                continue;
            }
            $autres[$cle] = mb_substr((string) $valeur, 0, 200);
        }

        $rendu = $this->view->render($response, 'pages/landing/lp.html.twig', [
            'click_id'   => $clickId,
            'forme_ok'   => $formeOk,
            'prefill'    => $prefill,
            'autres'     => $autres,
            'nb_prefill' => count($prefill),
            'secrets_masques' => $secretsMasques,
        ]);

        // Une page de test n'a rien a faire dans un index, et son URL porte un
        // identifiant de clic.
        return $rendu
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Cache-Control', 'no-store');
    }
}
