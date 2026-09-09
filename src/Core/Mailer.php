<?php

declare(strict_types=1);

namespace App\Core;

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Envoi de courriel. PHPMailer, comme le reste du parc.
 */
final class Mailer
{
    /** @return bool succes de la remise au serveur SMTP, pas de la delivrance */
    public static function send(string $to, string $subject, string $html, string $text = ''): bool
    {
        $host = Env::get('MAIL_HOST');
        if ($host === null) {
            error_log('[vigil/mail] MAIL_HOST absent : envoi impossible');
            return false;
        }

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host    = $host;
            $mail->Port    = (int) Env::get('MAIL_PORT', '25');
            $mail->CharSet = PHPMailer::CHARSET_UTF8;

            $user = Env::get('MAIL_USER');
            if ($user !== null) {
                $mail->SMTPAuth = true;
                $mail->Username = $user;
                $mail->Password = (string) Env::get('MAIL_PWD', '');
            }

            $encryption = Env::get('MAIL_ENCRYPTION');
            if ($encryption !== null) {
                $mail->SMTPSecure = $encryption;
            } else {
                // MailHog ne parle pas TLS : sans cette bascule, PHPMailer
                // tente STARTTLS et l'envoi echoue en developpement.
                $mail->SMTPAutoTLS = false;
            }

            $mail->setFrom(
                (string) Env::get('MAIL_FROM', 'vigil@localhost'),
                (string) Env::get('MAIL_FROM_NAME', 'Vigil')
            );
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body    = $html;
            $mail->AltBody = $text !== '' ? $text : strip_tags($html);

            return $mail->send();
        } catch (MailerException $e) {
            // Le contenu n'est jamais journalise : il porte un code a usage
            // unique. Seule la cause de l'echec l'est.
            error_log('[vigil/mail] echec vers ' . $to . ' : ' . $e->getMessage());
            return false;
        }
    }
}
