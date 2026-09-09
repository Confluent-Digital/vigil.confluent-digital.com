<?php

declare(strict_types=1);

namespace App\Tests\Http;

/**
 * L'ecran d'enrolement a longtemps rendu un `{% if %}` VIDE : un code refuse
 * rechargeait la page a l'identique, sans le moindre message. L'utilisateur
 * voyait « ca ne fonctionne pas » et n'avait aucun moyen de savoir pourquoi —
 * alors que la cause la plus frequente (un code lu sur une ancienne entree de
 * l'application) se corrige en dix secondes quand on la nomme.
 */
final class EnrollmentTest extends HttpTestCase
{
    /** @return array{status: int, headers: array<string,string>, body: string} */
    private function post(string $path, array $champs, string $cookieJar): array
    {
        $ch = curl_init($this->baseUrl() . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($champs),
            CURLOPT_COOKIEJAR      => $cookieJar,
            CURLOPT_COOKIEFILE     => $cookieJar,
        ]);
        $reponse = (string) curl_exec($ch);
        $taille  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        // Les en-tetes etaient jetes : impossible de verifier une redirection,
        // alors que tout le flux de connexion en est fait.
        $entetes = [];
        foreach (explode("\r\n", substr($reponse, 0, $taille)) as $ligne) {
            if (str_contains($ligne, ':')) {
                [$k, $v] = explode(':', $ligne, 2);
                $entetes[strtolower(trim($k))] = trim($v);
            }
        }

        return ['status' => $status, 'headers' => $entetes, 'body' => substr($reponse, $taille)];
    }

    /** @return array{status: int, location: string} */
    private function statusWithJar(string $path, string $cookieJar): array
    {
        $ch = curl_init($this->baseUrl() . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_COOKIEJAR      => $cookieJar,
            CURLOPT_COOKIEFILE     => $cookieJar,
        ]);
        $reponse = (string) curl_exec($ch);
        $status  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $taille  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $location = '';
        foreach (explode("\r\n", substr($reponse, 0, $taille)) as $ligne) {
            if (stripos($ligne, 'location:') === 0) {
                $location = trim(substr($ligne, 9));
            }
        }

        return ['status' => $status, 'location' => $location];
    }

    private function getWithJar(string $path, string $cookieJar): string
    {
        $ch = curl_init($this->baseUrl() . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_COOKIEJAR      => $cookieJar,
            CURLOPT_COOKIEFILE     => $cookieJar,
        ]);
        $body = (string) curl_exec($ch);
        curl_close($ch);

        return $body;
    }

    private static function csrf(string $html): string
    {
        preg_match('/name="csrf" value="([^"]+)"/', $html, $m);

        return $m[1] ?? '';
    }

    /**
     * Le test cree son PROPRE compte : on n'emprunte jamais celui d'une
     * personne reelle pour un essai — un enrolement detruit ne se recupere pas.
     */
    private function makeUser(string $motDePasse): array
    {
        $email = 'phpunit-' . bin2hex(random_bytes(4)) . '@exemple.test';

        $this->pdo->prepare(
            'INSERT INTO t_user (user_email, user_password, user_name, user_role)
             VALUES (:e, :p, :n, \'admin\')'
        )->execute([
            'e' => $email,
            'p' => password_hash($motDePasse, PASSWORD_BCRYPT),
            'n' => 'PHPUNIT-' . substr($email, 8, 8),
        ]);

        return ['id' => (int) $this->pdo->lastInsertId(), 'email' => $email];
    }

    /**
     * Un compte enrole PUIS prive de son secret (telephone perdu, secret
     * efface) ne doit pas retomber sur l'enrolement libre.
     *
     * Le defaut : `isEnrolled()` repondant non, on envoyait vers
     * `/login/enroll`. Quiconque detenait le mot de passe pouvait donc enroler
     * SON appareil — un secret absent desactivait le second facteur pour le
     * premier arrivant. Et la personne legitime, elle, ne se voyait jamais
     * proposer ses codes de secours, pourtant toujours valides.
     */
    public function testSansSecretMaisAvecCodesDeSecoursOnNePeutPasEnroler(): void
    {
        $mdp  = 'Motdepasse-' . bin2hex(random_bytes(6));
        $user = $this->makeUser($mdp);
        $jar  = tempnam(sys_get_temp_dir(), 'vigil');

        try {
            // Un code de secours en attente, aucun secret TOTP : l'etat exact
            // d'un compte dont l'enrolement a ete detruit.
            $this->pdo->prepare(
                'INSERT INTO t_user_recovery_code (recovery_id_user, recovery_hash)
                 VALUES (:i, :h)'
            )->execute(['i' => $user['id'], 'h' => password_hash('abcd-1234', PASSWORD_BCRYPT)]);

            $login = $this->getWithJar('/login', $jar);
            $r = $this->post('/login', [
                'csrf' => self::csrf($login), 'email' => $user['email'], 'password' => $mdp,
            ], $jar);

            self::assertSame(302, $r['status']);
            self::assertStringEndsWith(
                '/login/verify',
                $r['headers']['location'] ?? '',
                'un compte qui a encore des codes de secours passe par la verification'
            );

            // Et l'enrolement reste ferme meme en visant l'URL directement.
            $direct = $this->statusWithJar('/login/enroll', $jar);
            self::assertSame(302, $direct['status'], 'le GET /login/enroll doit rediriger');
            self::assertStringEndsWith('/login/verify', $direct['location']);

            // L'ecran nomme le code de secours, sinon on saisit indefiniment
            // un code a six chiffres que plus rien ne produit.
            $verify = $this->getWithJar('/login/verify', $jar);
            self::assertStringContainsString('code de secours', $verify);
        } finally {
            @unlink($jar);
            $this->pdo->prepare('DELETE FROM t_user_recovery_code WHERE recovery_id_user = :i')->execute(['i' => $user['id']]);
            $this->pdo->prepare('DELETE FROM t_login_attempt WHERE attempt_id_user = :i')->execute(['i' => $user['id']]);
            $this->pdo->prepare('DELETE FROM t_user WHERE user_id = :i')->execute(['i' => $user['id']]);
        }
    }

    public function testUnCodeRefuseAffricheUnMessageExplicite(): void
    {
        $mdp  = 'Motdepasse-' . bin2hex(random_bytes(6));
        $user = $this->makeUser($mdp);
        $jar  = tempnam(sys_get_temp_dir(), 'vigil');

        try {
            $login = $this->getWithJar('/login', $jar);
            $this->post('/login', [
                'csrf' => self::csrf($login), 'email' => $user['email'], 'password' => $mdp,
            ], $jar);

            $enroll = $this->getWithJar('/login/enroll', $jar);
            self::assertStringContainsString('secret-key', $enroll, 'la page d\'enrolement doit s\'afficher');

            $r = $this->post('/login/enroll', ['csrf' => self::csrf($enroll), 'code' => '000000'], $jar);

            self::assertSame(422, $r['status']);
            self::assertStringContainsString("Ce code n'a pas été accepté", $r['body']);
            self::assertStringContainsString('ancienne entrée', $r['body'], 'la cause la plus frequente doit etre nommee');
            self::assertStringContainsString('heure de votre téléphone', $r['body']);
        } finally {
            @unlink($jar);
            $this->pdo->prepare('DELETE FROM t_user_recovery_code WHERE recovery_id_user = :i')->execute(['i' => $user['id']]);
            $this->pdo->prepare('DELETE FROM t_login_attempt WHERE attempt_id_user = :i')->execute(['i' => $user['id']]);
            $this->pdo->prepare('DELETE FROM t_user WHERE user_id = :i')->execute(['i' => $user['id']]);
        }
    }

    public function testUnBonCodeMeneAuxCodesDeSecours(): void
    {
        $mdp  = 'Motdepasse-' . bin2hex(random_bytes(6));
        $user = $this->makeUser($mdp);
        $jar  = tempnam(sys_get_temp_dir(), 'vigil');

        try {
            $login = $this->getWithJar('/login', $jar);
            $this->post('/login', [
                'csrf' => self::csrf($login), 'email' => $user['email'], 'password' => $mdp,
            ], $jar);

            $enroll = $this->getWithJar('/login/enroll', $jar);
            preg_match('/secret-key">([^<]+)</', $enroll, $m);
            $secret = str_replace(' ', '', $m[1] ?? '');
            self::assertNotSame('', $secret);

            $r = $this->post('/login/enroll', [
                'csrf' => self::csrf($enroll),
                'code' => \App\Core\Totp::code($secret),
            ], $jar);

            self::assertSame(302, $r['status'], 'un code valide doit aboutir');

            $codes = $this->getWithJar('/app/recovery-codes', $jar);
            self::assertSame(
                \App\Core\TwoFactor::RECOVERY_CODE_COUNT,
                substr_count($codes, 'class="recovery-code"'),
                'les huit codes de secours doivent etre affiches'
            );
        } finally {
            @unlink($jar);
            $this->pdo->prepare('DELETE FROM t_user_recovery_code WHERE recovery_id_user = :i')->execute(['i' => $user['id']]);
            $this->pdo->prepare('DELETE FROM t_login_attempt WHERE attempt_id_user = :i')->execute(['i' => $user['id']]);
            $this->pdo->prepare('DELETE FROM t_user WHERE user_id = :i')->execute(['i' => $user['id']]);
        }
    }
}
