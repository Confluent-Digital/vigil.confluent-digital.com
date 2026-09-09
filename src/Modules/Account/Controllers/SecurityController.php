<?php

declare(strict_types=1);

namespace App\Modules\Account\Controllers;

use App\Core\Auth;
use App\Core\ClickContext;
use App\Core\Database;
use App\Core\LoginGuard;
use App\Core\TwoFactor;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/** Écran de sécurité du compte : codes de secours et journal des connexions. */
final class SecurityController
{
    public function __construct(private readonly Twig $view)
    {
    }

    /**
     * Affiche les codes de secours fraichement generes. Ils ne transitent que
     * par la session et ne sont montres qu'une fois : en base ils sont hashes,
     * personne — pas meme un administrateur — ne pourra les relire.
     */
    public function recoveryCodes(Request $request, Response $response): Response
    {
        $codes = $_SESSION['show_recovery_codes'] ?? null;
        unset($_SESSION['show_recovery_codes']);

        if (!is_array($codes) || $codes === []) {
            return $response->withHeader('Location', '/app/security')->withStatus(302);
        }

        return $this->view->render($response, 'pages/account/recovery_codes.html.twig', [
            'codes'       => $codes,
            'active_page' => 'security',
        ]);
    }

    public function index(Request $request, Response $response): Response
    {
        $user = Auth::user();
        $id   = (int) $user['id'];

        // Le journal montre en clair les connexions passees par le repli
        // courriel : c'est le seul moyen de reperer qu'une boite mail sert a
        // contourner l'application d'authentification.
        $stmt = Database::get()->prepare(
            "SELECT attempt_event, attempt_date, INET6_NTOA(attempt_ip) AS ip, attempt_user_agent
               FROM t_login_attempt
              WHERE attempt_id_user = :id
           ORDER BY attempt_id DESC LIMIT 40"
        );
        $stmt->execute(['id' => $id]);

        $fallback = Database::get()->prepare(
            "SELECT COUNT(*) FROM t_login_attempt
              WHERE attempt_id_user = :id AND attempt_event = 'email_code_ok'
                AND attempt_date > UTC_TIMESTAMP() - INTERVAL 30 DAY"
        );
        $fallback->execute(['id' => $id]);

        return $this->view->render($response, 'pages/account/security.html.twig', [
            'remaining_codes'    => TwoFactor::remainingRecoveryCodes($id),
            'total_codes'        => TwoFactor::RECOVERY_CODE_COUNT,
            'attempts'           => $stmt->fetchAll(),
            'fallback_30d'       => (int) $fallback->fetchColumn(),
            'active_page'        => 'security',
        ]);
    }

    public function regenerate(Request $request, Response $response): Response
    {
        $user = Auth::user();
        $ip   = ClickContext::clientIp($request->getServerParams());

        $_SESSION['show_recovery_codes'] = TwoFactor::regenerateRecoveryCodes((int) $user['id']);
        LoginGuard::record((string) $user['email'], (int) $user['id'], 'enrolled', $ip);

        return $response->withHeader('Location', '/app/recovery-codes')->withStatus(302);
    }
}
