<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Second facteur d'authentification.
 *
 * Le back-office permet de changer l'URL de destination d'une campagne — donc
 * de detourner tout le trafic — et de lire les secrets de postback. Le mot de
 * passe seul ne suffit pas.
 */
final class AddTwoFactorToUser extends AbstractMigration
{
    public function change(): void
    {
        $this->table('t_user')
            // Secret TOTP chiffre au repos (sodium secretbox, cle derivee de
            // APP_SECRET). En clair en base, une lecture SQL suffirait a
            // fabriquer des codes valides : le second facteur ne protegerait
            // plus de rien face a une fuite de dump.
            ->addColumn('user_totp_secret', 'blob', ['null' => true, 'after' => 'user_password'])
            ->addColumn('user_totp_enabled_at', 'datetime', ['null' => true, 'after' => 'user_totp_secret'])
            // Dernier pas de temps consomme : un code TOTP reste valide 30 s,
            // sans cette borne il serait rejouable pendant toute sa fenetre.
            ->addColumn('user_totp_last_step', 'biginteger', ['null' => true, 'after' => 'user_totp_enabled_at'])
            ->update();

        // Codes de secours a usage unique, stockes hashes : ils valent un mot
        // de passe.
        $this->table('t_user_recovery_code', ['id' => 'recovery_id'])
            ->addColumn('recovery_id_user', 'integer', ['signed' => false])
            ->addColumn('recovery_hash', 'string', ['limit' => 255])
            ->addColumn('recovery_used_at', 'datetime', ['null' => true])
            ->addColumn('recovery_date_create', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['recovery_id_user'])
            ->create();

        // Repli par courriel. Le code est hashe : un dump ne doit pas permettre
        // de se connecter avec un code encore valide.
        $this->table('t_user_email_code', ['id' => 'emailcode_id'])
            ->addColumn('emailcode_id_user', 'integer', ['signed' => false])
            ->addColumn('emailcode_hash', 'string', ['limit' => 255])
            ->addColumn('emailcode_expires_at', 'datetime')
            ->addColumn('emailcode_used_at', 'datetime', ['null' => true])
            ->addColumn('emailcode_attempts', 'integer', ['limit' => 4, 'default' => 0])
            ->addColumn('emailcode_ip', 'varbinary', ['limit' => 16, 'null' => true])
            ->addColumn('emailcode_date_create', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['emailcode_id_user', 'emailcode_expires_at'])
            ->create();

        // Journal d'authentification : sert au plafonnement des tentatives ET
        // a la tracabilite. Un contournement par le repli mail ne doit jamais
        // etre silencieux.
        $this->table('t_login_attempt', ['id' => 'attempt_id'])
            ->addColumn('attempt_email', 'string', ['limit' => 190])
            ->addColumn('attempt_id_user', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('attempt_event', 'enum', ['values' => [
                'password_ok', 'password_fail', 'totp_ok', 'totp_fail',
                'email_code_sent', 'email_code_ok', 'email_code_fail',
                'recovery_ok', 'recovery_fail', 'enrolled', 'locked',
            ]])
            ->addColumn('attempt_ip', 'varbinary', ['limit' => 16, 'null' => true])
            ->addColumn('attempt_user_agent', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('attempt_date', 'datetime', [
                'precision' => 3, 'default' => 'CURRENT_TIMESTAMP',
            ])
            ->addIndex(['attempt_email', 'attempt_date'])
            ->addIndex(['attempt_ip', 'attempt_date'])
            ->addIndex(['attempt_id_user', 'attempt_date'])
            ->create();
    }
}
