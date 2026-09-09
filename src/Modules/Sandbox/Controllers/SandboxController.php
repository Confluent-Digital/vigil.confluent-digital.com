<?php

declare(strict_types=1);

namespace App\Modules\Sandbox\Controllers;

use App\Core\CampaignCache;
use App\Core\AppUrl;
use App\Core\Database;
use App\Core\Env;
use App\Core\Prefill;
use App\Core\Sandbox;
use App\Core\Token;
use App\Core\Ulid;
use App\Modules\Sandbox\Fixtures;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Le bac a sable. Voir `App\Core\Sandbox` : ces routes n'existent pas en
 * production, elles ne sont pas enregistrees.
 */
final class SandboxController
{
    public function __construct(private readonly Twig $view)
    {
    }

    // ── Tableau de bord ─────────────────────────────────────────────────────

    public function index(Request $request, Response $response): Response
    {
        $jeu = $this->jeuExistant();

        return $this->view->render($response, 'pages/sandbox/index.html.twig', [
            'jeu'         => $jeu,
            'resume'      => Fixtures::summary(),
            'testeur'     => $_SESSION['sandbox_testeur'] ?? null,
            'liens'       => $this->liens(),
            'base_url'    => AppUrl::base($request),
            'url_ecart'   => AppUrl::matchesRequest($request) ? null : AppUrl::fromRequest($request),
            'journal'     => Sandbox::journal(),
            'active_page' => 'sandbox',
        ]);
    }

    /**
     * Cree le jeu de demonstration : son propre client, publisher, campagne,
     * lien et destination de relai. Rien n'est greffe sur des donnees reelles,
     * donc rien ne pollue les statistiques.
     */
    /**
     * Cree le jeu complet : plusieurs clients, publishers, campagnes, une
     * matrice d'acces incomplete, une destination par campagne et le pixel de
     * trois publishers sur quatre.
     */
    public function seed(Request $request, Response $response): Response
    {
        $c       = Fixtures::create();
        $testeur = Fixtures::createTestUser();

        // Affiche une seule fois : le mot de passe n'est pas relisible ensuite.
        $_SESSION['sandbox_testeur'] = $testeur;

        Sandbox::clearJournal();
        Sandbox::log('jeu', 'Jeu de test cree', $c);

        $_SESSION['flash_ok'] = sprintf(
            '%d clients, %d publishers, %d campagnes, %d acces et %d destinations créés.',
            $c['clients'], $c['publishers'], $c['campagnes'], $c['acces'], $c['destinations']
        );

        return $response->withHeader('Location', '/sandbox')->withStatus(302);
    }

    /** Genere du trafic historique, pour remplir les ecrans. */
    public function traffic(Request $request, Response $response): Response
    {
        if (Fixtures::summary()['campagnes'] === 0) {
            $_SESSION['flash_error'] = "Créez d'abord le jeu de test.";

            return $response->withHeader('Location', '/sandbox')->withStatus(302);
        }

        $r = Fixtures::traffic();

        // Les statistiques se lisent dans le pre-agregat : sans ce calcul, le
        // tableau de bord resterait a zero alors que les clics existent.
        (new \App\Modules\Stats\Tasks\StatsAggregateTask(dirname(__DIR__, 4) . '/logs'))->run(24 * 8);

        Sandbox::log('trafic', 'Trafic historique genere', $r);

        $_SESSION['flash_ok'] = sprintf(
            '%d clics, %d conversions et %d relais générés sur les 7 derniers jours. '
            . 'Statistiques recalculées.',
            $r['clics'], $r['conversions'], $r['relais']
        );

        return $response->withHeader('Location', '/sandbox')->withStatus(302);
    }

    public function reset(Request $request, Response $response): Response
    {
        Fixtures::destroy();
        Sandbox::clearJournal();

        $_SESSION['flash_ok'] = 'Bac à sable vidé — seules les données préfixées « '
            . Sandbox::PREFIX . ' » ont été supprimées.';

        return $response->withHeader('Location', '/sandbox')->withStatus(302);
    }

    // ── La fausse plateforme Confluent Digital, cote DEPART ─────────────────

    /**
     * Le maillon qui manquait.
     *
     * Le bac a sable demarrait au lien Vigil : on voyait la plateforme externe
     * RECEVOIR le relai, jamais l'EMETTRE. Or c'est tout l'interet de Vigil —
     * la plateforme qui a envoye le visiteur recupere SON identifiant a la fin.
     * Sans les deux bouts, la boucle ne se referme pas et le mecanisme reste
     * invisible.
     *
     * Cette page joue le role de votre plateforme : elle fabrique son propre
     * identifiant de clic, l'affiche, et envoie le visiteur vers Vigil en le
     * lui passant.
     */
    public function platformOut(Request $request, Response $response): Response
    {
        $token = trim((string) ($request->getQueryParams()['token'] ?? ''));

        $stmt = Database::get()->prepare(
            'SELECT cp.cp_token, c.campaign_name, p.publisher_name, cl.client_name
               FROM t_campaign_publisher cp
               JOIN t_campaign  c  ON c.campaign_id  = cp.cp_id_campaign
               JOIN t_client    cl ON cl.client_id   = c.campaign_id_client
               JOIN t_publisher p  ON p.publisher_id = cp.cp_id_publisher
              WHERE cp.cp_token = :t LIMIT 1'
        );
        $stmt->execute(['t' => $token]);
        $lien = $stmt->fetch();

        if ($lien === false) {
            $_SESSION['flash_error'] = 'Lien inconnu. Recréez le jeu de test.';

            return $response->withHeader('Location', '/sandbox')->withStatus(302);
        }

        // L'identifiant que VOTRE plateforme fabrique et suit de bout en bout.
        // C'est lui qu'on doit retrouver, a l'identique, dans le pixel final.
        $clicPlateforme = 'CD-' . strtoupper(Token::generate(8));

        $destination = Sandbox::publicBaseUrl() . '/c/' . $lien['cp_token']
            . '?' . http_build_query([
                'cid'    => $clicPlateforme,
                'prenom' => 'Marie Claire',
                'nom'    => 'Durand',
                'email'  => 'marie.durand@exemple.fr',
                'cp'     => '75011',
                'ville'  => 'Paris',
                'tel'    => '0612345678',
                'civ'    => 'Mme',
            ]);

        Sandbox::log('depart', 'La plateforme Confluent Digital a émis un clic', [
            'son_clickid' => $clicPlateforme,
            'campagne'    => str_replace(Sandbox::PREFIX . ' ', '', (string) $lien['campaign_name']),
            'publisher'   => str_replace(Sandbox::PREFIX . ' ', '', (string) $lien['publisher_name']),
        ]);

        return $this->view->render($response, 'pages/sandbox/platform_out.html.twig', [
            'lien'            => $lien,
            'clic_plateforme' => $clicPlateforme,
            'destination'     => $destination,
        ]);
    }

    // ── Le faux money site ──────────────────────────────────────────────────

    /** Page d'atterrissage : elle affiche exactement ce qu'elle a reçu. */
    public function moneySite(Request $request, Response $response): Response
    {
        $recu    = $request->getQueryParams();
        $clickId = (string) ($recu['clickid'] ?? '');

        Sandbox::log('atterrissage', 'Le money site a reçu le visiteur', [
            'clickid'  => $clickId,
            'prefill'  => array_intersect_key($recu, Prefill::FIELDS),
        ]);

        $jeu = $this->jeuExistant();

        return $this->view->render($response, 'pages/sandbox/money_site.html.twig', [
            'recu'          => $recu,
            'clickid'       => $clickId,
            'clickid_valide' => Ulid::isValid($clickId),
            'prefill'       => array_intersect_key($recu, Prefill::FIELDS),
            'champs'        => Prefill::FIELDS,
            'secret'        => $jeu['secret'] ?? '',
            'txid_suggere'  => 'CMD-' . strtoupper(Token::generate(6)),
        ]);
    }

    /**
     * « Le client paie. » Le faux money site appelle `/pb` cote serveur, en
     * HTTP, exactement comme le ferait un vrai — c'est ce qui rend l'exercice
     * fidele : nginx, PHP-FPM et les en-têtes sont traversés pour de bon.
     */
    public function convert(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();

        $params = array_filter([
            'clickid' => (string) ($body['clickid'] ?? ''),
            'txid'    => (string) ($body['txid'] ?? ''),
            'payout'  => (string) ($body['payout'] ?? ''),
            'status'  => (string) ($body['status'] ?? 'approved'),
            's'       => (string) ($body['secret'] ?? ''),
        ], static fn (string $v): bool => $v !== '');

        $resultat = Sandbox::call('/pb?' . http_build_query($params));

        Sandbox::log('postback', 'Le money site a appelé /pb', [
            'params'   => $params,
            'http'     => $resultat['code'],
            'reponse'  => $resultat['body'],
        ]);

        $this->journaliserEffet($params['clickid'] ?? '', $params['txid'] ?? '');

        $_SESSION['flash_ok'] = sprintf(
            'Postback envoyé : HTTP %d, réponse « %s ».',
            $resultat['code'],
            trim($resultat['body'])
        );

        return $response->withHeader('Location', '/sandbox')->withStatus(302);
    }

    // ── La fausse plateforme externe ────────────────────────────────────────

    /** Reçoit le relai S2S et le journalise. C'est le bout de la chaîne. */
    public function platform(Request $request, Response $response): Response
    {
        $recu = array_merge($request->getQueryParams(), (array) $request->getParsedBody());

        // On rapproche l'identifiant recu de celui emis au depart : c'est LA
        // chose a voir, et elle ne saute pas aux yeux dans un journal brut.
        $emis = null;
        foreach (Sandbox::journal() as $e) {
            if ($e['etape'] === 'depart') {
                $emis = $e['donnees']['son_clickid'] ?? null;
                break;
            }
        }

        $recuCid = $recu['cid'] ?? null;

        Sandbox::log(
            'relai',
            $emis !== null && $recuCid === $emis
                ? 'La plateforme a reçu SON identifiant de départ — la boucle est fermée'
                : 'La plateforme externe a reçu le relai',
            [
                'params'        => $recu,
                'emis_au_depart' => $emis,
                'boucle_fermee'  => $emis !== null ? ($recuCid === $emis) : null,
            ]
        );

        $response->getBody()->write('OK');

        return $response->withHeader('Content-Type', 'text/plain');
    }

    /** Force le worker à vider la file, pour ne pas attendre la minute du cron. */
    public function flush(Request $request, Response $response): Response
    {
        $envoyes = (new \App\Modules\Postback\Tasks\PostbackFlushTask(
            dirname(__DIR__, 4) . '/logs'
        ))->send();

        $_SESSION['flash_ok'] = $envoyes . ' relai(s) traité(s).';

        return $response->withHeader('Location', '/sandbox')->withStatus(302);
    }

    public function clearJournal(Request $request, Response $response): Response
    {
        Sandbox::clearJournal();

        return $response->withHeader('Location', '/sandbox')->withStatus(302);
    }

    // ── Interne ─────────────────────────────────────────────────────────────

    /**
     * Tous les liens de tracking du jeu, avec leur campagne et leur publisher.
     * C'est la matrice d'acces rendue lisible : les couples absents n'ont pas
     * de ligne, parce qu'ils n'ont pas de jeton.
     *
     * @return array<int, array<string, mixed>>
     */
    private function liens(): array
    {
        $stmt = Database::get()->prepare(
            'SELECT cp.cp_token, c.campaign_id, c.campaign_name, c.campaign_payout,
                    c.campaign_postback_secret, p.publisher_id, p.publisher_name,
                    cl.client_name,
                    EXISTS(SELECT 1 FROM t_campaign_postback pb
                            WHERE pb.cpb_id_publisher = p.publisher_id
                              AND pb.cpb_id_campaign IS NULL
                              AND pb.cpb_status = \'active\') AS a_pixel
               FROM t_campaign_publisher cp
               JOIN t_campaign  c  ON c.campaign_id  = cp.cp_id_campaign
               JOIN t_client    cl ON cl.client_id   = c.campaign_id_client
               JOIN t_publisher p  ON p.publisher_id = cp.cp_id_publisher
              WHERE cl.client_name LIKE :m
           ORDER BY cl.client_name, c.campaign_name, p.publisher_name'
        );
        $stmt->execute(['m' => Sandbox::PREFIX . '%']);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    private function jeuExistant(): ?array
    {
        $stmt = Database::get()->prepare(
            'SELECT c.campaign_id, c.campaign_name, c.campaign_payout, c.campaign_postback_secret AS secret,
                    cp.cp_token AS token, p.publisher_name,
                    (SELECT COUNT(*) FROM t_click WHERE click_id_campaign = c.campaign_id) AS nb_clics,
                    (SELECT COUNT(*) FROM t_conversion WHERE conversion_id_campaign = c.campaign_id) AS nb_conversions,
                    (SELECT COUNT(*) FROM t_postback_queue q
                       JOIN t_conversion v ON v.conversion_id = q.pq_id_conversion
                      WHERE v.conversion_id_campaign = c.campaign_id) AS nb_relais,
                    (SELECT COUNT(*) FROM t_postback_queue q
                       JOIN t_conversion v ON v.conversion_id = q.pq_id_conversion
                      WHERE v.conversion_id_campaign = c.campaign_id AND q.pq_status = \'pending\') AS nb_attente
               FROM t_campaign c
               JOIN t_client cl ON cl.client_id = c.campaign_id_client
               JOIN t_campaign_publisher cp ON cp.cp_id_campaign = c.campaign_id
               JOIN t_publisher p ON p.publisher_id = cp.cp_id_publisher
              WHERE cl.client_name LIKE :n
              LIMIT 1'
        );
        $stmt->execute(['n' => Sandbox::PREFIX . '%']);

        return $stmt->fetch() ?: null;
    }

    private function journaliserEffet(string $clickId, string $txid): void
    {
        if ($clickId === '' || !Ulid::isValid($clickId)) {
            return;
        }

        $stmt = Database::get()->prepare(
            'SELECT conversion_id, conversion_status, conversion_payout,
                    (SELECT COUNT(*) FROM t_postback_queue WHERE pq_id_conversion = conversion_id) AS relais
               FROM t_conversion
              WHERE conversion_id_click = :i ORDER BY conversion_id DESC LIMIT 1'
        );
        $stmt->execute(['i' => Ulid::toBinary($clickId)]);
        $ligne = $stmt->fetch();

        Sandbox::log(
            'conversion',
            $ligne === false ? 'Aucune conversion créée — postback refusé ou clic inconnu'
                             : 'Conversion enregistrée',
            $ligne === false ? [] : [
                'id'      => $ligne['conversion_id'],
                'statut'  => $ligne['conversion_status'],
                'payout'  => $ligne['conversion_payout'],
                'relais'  => $ligne['relais'],
            ]
        );
    }

    private function purge(\PDO $pdo): void
    {
        $ids = $pdo->prepare(
            'SELECT c.campaign_id FROM t_campaign c
               JOIN t_client cl ON cl.client_id = c.campaign_id_client
              WHERE cl.client_name = :n'
        );
        $ids->execute(['n' => Sandbox::CLIENT]);

        foreach ($ids->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $pdo->prepare(
                'DELETE q FROM t_postback_queue q
                   JOIN t_conversion v ON v.conversion_id = q.pq_id_conversion
                  WHERE v.conversion_id_campaign = :i'
            )->execute(['i' => $id]);
            foreach (['t_conversion' => 'conversion_id_campaign', 't_click' => 'click_id_campaign',
                      't_campaign_publisher' => 'cp_id_campaign', 't_campaign_postback' => 'cpb_id_campaign',
                      't_stats_hourly' => 'stats_id_campaign'] as $table => $col) {
                $pdo->prepare("DELETE FROM $table WHERE $col = :i")->execute(['i' => $id]);
            }
            $pdo->prepare('DELETE FROM t_campaign WHERE campaign_id = :i')->execute(['i' => $id]);
        }

        $pdo->prepare('DELETE FROM t_publisher WHERE publisher_name LIKE \'[BAC A SABLE]%\'')->execute();
        $pdo->prepare('DELETE FROM t_client WHERE client_name = :n')->execute(['n' => Sandbox::CLIENT]);
    }
}
