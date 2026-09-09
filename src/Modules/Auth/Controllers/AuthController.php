<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Core\Auth;
use App\Core\ClickContext;
use App\Core\Env;
use App\Core\LoginGuard;
use App\Core\Totp;
use App\Core\TwoFactor;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Connexion en deux temps.
 *
 *   1. mot de passe  -> session EN ATTENTE (Auth::check() reste faux)
 *   2. second facteur -> session complete
 *
 * Le second facteur est obligatoire pour tout le monde : un compte qui n'est
 * pas encore enrole est envoye sur l'enrolement, sans possibilite de le sauter.
 */
final class AuthController
{
    public function __construct(private readonly Twig $view)
    {
    }

    // ── Étape 1 : mot de passe ──────────────────────────────────────────────

    public function loginForm(Request $request, Response $response): Response
    {
        if (Auth::check()) {
            return $response->withHeader('Location', '/app')->withStatus(302);
        }

        return $this->view->render($response, 'pages/auth/login.html.twig');
    }

    public function login(Request $request, Response $response): Response
    {
        $body  = (array) $request->getParsedBody();
        $email = trim((string) ($body['email'] ?? ''));
        $ip    = ClickContext::clientIp($request->getServerParams());
        $ua    = $request->getHeaderLine('User-Agent');

        if (LoginGuard::isLocked($email, $ip)) {
            LoginGuard::record($email, null, 'locked', $ip, $ua);

            return $this->view->render(
                $response->withStatus(429),
                'pages/auth/login.html.twig',
                [
                    'error' => sprintf(
                        'Trop de tentatives. Réessayez dans %d minute(s).',
                        LoginGuard::lockMinutesLeft($email, $ip)
                    ),
                    'email' => $email,
                ]
            );
        }

        $user = Auth::attemptPassword($email, (string) ($body['password'] ?? ''));

        if ($user === null) {
            LoginGuard::record($email, null, 'password_fail', $ip, $ua);

            // Message unique : distinguer « e-mail inconnu » de « mot de passe
            // faux » donne un oracle pour enumerer les comptes.
            return $this->view->render(
                $response->withStatus(401),
                'pages/auth/login.html.twig',
                ['error' => 'Identifiants invalides.', 'email' => $email]
            );
        }

        LoginGuard::record($email, (int) $user['user_id'], 'password_ok', $ip, $ua);

        return $response
            ->withHeader('Location', TwoFactor::isEnrolled($user) ? '/login/verify' : '/login/enroll')
            ->withStatus(302);
    }

    // ── Étape 2a : enrôlement, quand le compte n'a pas encore d'application ──

    /**
     * Handler de route. Sa signature ne peut PAS porter de parametre en plus :
     * Slim passe toujours les arguments de route en troisieme position, et un
     * `?string` y recevrait un tableau — TypeError au boot de la page. Le
     * rendu vit donc dans `renderEnroll()`.
     */
    public function enrollForm(Request $request, Response $response): Response
    {
        return $this->renderEnroll($response);
    }

    private function renderEnroll(Response $response, ?string $erreur = null): Response
    {
        $user = Auth::pendingUser();
        if ($user === null) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }
        if (TwoFactor::isEnrolled($user)) {
            return $response->withHeader('Location', '/login/verify')->withStatus(302);
        }

        // Le secret vit en session tant qu'il n'est pas confirme : ecrit en
        // base avant la preuve, un scan rate verrouillerait le compte.
        $secret = $_SESSION['enroll_secret'] ?? null;
        if (!is_string($secret) || $secret === '') {
            $secret = TwoFactor::beginEnrollment();
            $_SESSION['enroll_secret'] = $secret;
        }

        $issuer = (string) Env::get('TOTP_ISSUER', 'Vigil');
        $uri    = Totp::provisioningUri($secret, (string) $user['user_email'], $issuer);

        return $this->view->render($response, 'pages/auth/enroll.html.twig', [
            'qr_svg'         => TwoFactor::qrCodeSvg($uri),
            'secret_display' => Totp::formatForDisplay($secret),
            'user_email'     => $user['user_email'],
            'issuer'         => $issuer,
            'error'          => $erreur,
        ]);
    }

    public function enroll(Request $request, Response $response): Response
    {
        $user = Auth::pendingUser();
        if ($user === null) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $secret = $_SESSION['enroll_secret'] ?? '';
        $body   = (array) $request->getParsedBody();
        $ip     = ClickContext::clientIp($request->getServerParams());

        $codes = is_string($secret) && $secret !== ''
            ? TwoFactor::completeEnrollment(
                (int) $user['user_id'],
                $secret,
                (string) ($body['code'] ?? '')
            )
            : null;

        if ($codes === null) {
            LoginGuard::record((string) $user['user_email'], (int) $user['user_id'], 'totp_fail', $ip);

            // Un code refuse doit se VOIR. Sans message, l'ecran se recharge a
            // l'identique et rien n'indique ce qui s'est passe : la cause la
            // plus frequente — un code lu sur une ancienne entree encore
            // presente dans l'application — reste invisible.
            return $this->renderEnroll($response->withStatus(422), 'code-refuse');
        }

        LoginGuard::record((string) $user['user_email'], (int) $user['user_id'], 'enrolled', $ip);

        // Les codes de secours ne sont montres qu'une fois : ils sont hashes en
        // base, personne ne pourra les reafficher ensuite.
        $_SESSION['show_recovery_codes'] = $codes;
        Auth::completeLogin($user);

        return $response->withHeader('Location', '/app/recovery-codes')->withStatus(302);
    }

    // ── Étape 2b : vérification, compte déjà enrôlé ──────────────────────────

    public function verifyForm(Request $request, Response $response): Response
    {
        $user = Auth::pendingUser();
        if ($user === null) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        return $this->view->render($response, 'pages/auth/verify.html.twig', [
            'user_email'   => $user['user_email'],
            'code_sent'    => !empty($_SESSION['email_code_sent']),
            'can_send_mail' => LoginGuard::canSendEmailCode((int) $user['user_id']),
        ]);
    }

    public function verify(Request $request, Response $response): Response
    {
        $user = Auth::pendingUser();
        if ($user === null) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $body   = (array) $request->getParsedBody();
        $email  = (string) $user['user_email'];
        $userId = (int) $user['user_id'];
        $ip     = ClickContext::clientIp($request->getServerParams());
        $ua     = $request->getHeaderLine('User-Agent');
        $code   = trim((string) ($body['code'] ?? ''));

        if (LoginGuard::isLocked($email, $ip)) {
            return $this->verifyError($request, $response, sprintf(
                'Trop de tentatives. Réessayez dans %d minute(s).',
                LoginGuard::lockMinutesLeft($email, $ip)
            ), 429);
        }

        // Trois voies acceptées, dans cet ordre : l'application, le code reçu
        // par courriel, puis un code de secours. Le code de secours est testé
        // en dernier — il contient un tiret, ce qui le distingue, mais on ne
        // veut surtout pas en consommer un pour une simple faute de frappe.
        if (TwoFactor::verifyTotp($user, $code)) {
            LoginGuard::record($email, $userId, 'totp_ok', $ip, $ua);

            return $this->succeed($user, $email, $response);
        }

        if (!empty($_SESSION['email_code_sent']) && TwoFactor::verifyEmailCode($userId, $code)) {
            LoginGuard::record($email, $userId, 'email_code_ok', $ip, $ua);

            // Le repli a servi : l'utilisateur doit le savoir. C'est ce qui
            // permet de detecter qu'une boite mail a ete compromise.
            TwoFactor::notifyFallbackUsed($user, $ip);
            unset($_SESSION['email_code_sent']);

            return $this->succeed($user, $email, $response);
        }

        if (str_contains($code, '-') && TwoFactor::consumeRecoveryCode($userId, $code)) {
            LoginGuard::record($email, $userId, 'recovery_ok', $ip, $ua);
            $_SESSION['flash_error'] = sprintf(
                'Connexion par code de secours. Il vous en reste %d — régénérez-les depuis Sécurité.',
                TwoFactor::remainingRecoveryCodes($userId)
            );

            return $this->succeed($user, $email, $response);
        }

        LoginGuard::record($email, $userId, 'totp_fail', $ip, $ua);

        return $this->verifyError($request, $response, 'Code invalide ou expiré.', 401);
    }

    /** Envoie un code par courriel — à l'adresse du compte, jamais à une autre. */
    public function sendEmailCode(Request $request, Response $response): Response
    {
        $user = Auth::pendingUser();
        if ($user === null) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $userId = (int) $user['user_id'];
        $ip     = ClickContext::clientIp($request->getServerParams());

        if (!LoginGuard::canSendEmailCode($userId)) {
            $_SESSION['flash_error'] = 'Trop de codes demandés. Patientez une heure.';

            return $response->withHeader('Location', '/login/verify')->withStatus(302);
        }

        if (TwoFactor::sendEmailCode($user, $ip)) {
            LoginGuard::record((string) $user['user_email'], $userId, 'email_code_sent', $ip);
            $_SESSION['email_code_sent'] = true;
            $_SESSION['flash_ok'] = 'Code envoyé à ' . self::maskEmail((string) $user['user_email']) . '.';
        } else {
            $_SESSION['flash_error'] = "L'envoi a échoué. Utilisez votre application ou un code de secours.";
        }

        return $response->withHeader('Location', '/login/verify')->withStatus(302);
    }

    public function logout(Request $request, Response $response): Response
    {
        Auth::logout();

        return $response->withHeader('Location', '/login')->withStatus(302);
    }

    // ── Interne ─────────────────────────────────────────────────────────────

    private function succeed(array $user, string $email, Response $response): Response
    {
        LoginGuard::clearFailures($email);
        Auth::completeLogin($user);

        return $response->withHeader('Location', '/app')->withStatus(302);
    }

    private function verifyError(Request $request, Response $response, string $message, int $status): Response
    {
        $user = Auth::pendingUser();

        return $this->view->render(
            $response->withStatus($status),
            'pages/auth/verify.html.twig',
            [
                'error'         => $message,
                'user_email'    => $user['user_email'] ?? '',
                'code_sent'     => !empty($_SESSION['email_code_sent']),
                'can_send_mail' => $user !== null
                    && LoginGuard::canSendEmailCode((int) $user['user_id']),
            ]
        );
    }

    /** `ad***@confluent-digital.com` — confirme l'adresse sans la divulguer. */
    private static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $keep = min(2, max(1, mb_strlen($local) - 1));

        return mb_substr($local, 0, $keep) . str_repeat('*', 3) . '@' . $domain;
    }
}
