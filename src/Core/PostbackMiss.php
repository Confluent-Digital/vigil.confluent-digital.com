<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use Throwable;

/**
 * Journalisation des postbacks refuses.
 *
 * `/pb` rend toujours 200, meme quand il refuse. Sans cette trace, brancher une
 * campagne se fait a l'aveugle : le money site voit « HTTP 200 » et croit que
 * ca marche, alors qu'aucune conversion n'est creee.
 */
final class PostbackMiss
{
    /** Libelle et cause probable, dans le vocabulaire de celui qui branche. */
    public const RAISONS = [
        'clickid_absent' => [
            'Aucun clickid transmis',
            'Le money site n\'a pas renvoyé le paramètre. Vérifiez qu\'il utilise bien la macro que vous lui avez donnée.',
        ],
        'clickid_malforme' => [
            'Clickid malformé',
            'La valeur reçue n\'est pas un identifiant Vigil. Le money site renvoie sans doute le sien, ou une macro non substituée.',
        ],
        'clickid_inconnu' => [
            'Clickid inconnu',
            'Aucun clic ne correspond. Le clic est peut-être antérieur à la période conservée, ou le money site invente la valeur.',
        ],
        'campagne_introuvable' => [
            'Campagne supprimée',
            'Le clic référence une campagne qui n\'existe plus.',
        ],
        'secret_invalide' => [
            'Secret de postback invalide',
            'Le paramètre &s= est absent ou faux. C\'est la cause la plus fréquente lors d\'un premier branchement.',
        ],
        'ip_refusee' => [
            'IP non autorisée',
            'L\'appel vient d\'une adresse absente de la liste de la campagne.',
        ],
        'erreur_interne' => [
            'Erreur interne',
            'Le postback a échoué de notre côté. Consultez les journaux du conteneur PHP.',
        ],
    ];

    /**
     * Enregistre un refus. N'echoue jamais bruyamment : un defaut de
     * journalisation ne doit pas transformer un refus en 500.
     *
     * @param array<string, mixed> $requete la query recue, pour diagnostic
     */
    public static function record(
        string $raison,
        ?int $campaignId,
        array $requete,
        ?string $ip,
        string $detail = ''
    ): void {
        try {
            // Le secret est retire avant journalisation : il n'a rien a faire
            // dans une table que le back-office affiche.
            // Marqueur alphanumerique : `http_build_query` encoderait des
            // asterisques en %2A, et la trace afficherait du bruit la ou l'on
            // veut lire « ce champ existait, il a ete retire ».
            foreach (['s', 'secret'] as $cle) {
                if (isset($requete[$cle])) {
                    $requete[$cle] = 'MASQUE';
                }
            }

            $query = http_build_query($requete);

            Database::get()->prepare(
                'INSERT INTO t_postback_miss
                     (pmiss_date, pmiss_reason, pmiss_id_campaign, pmiss_count,
                      pmiss_first_at, pmiss_last_at, pmiss_last_ip,
                      pmiss_last_query, pmiss_last_detail)
                 VALUES (UTC_DATE(), :r, :c, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(),
                         INET6_ATON(:ip), :q, :d)
                 ON DUPLICATE KEY UPDATE
                     pmiss_count      = pmiss_count + 1,
                     pmiss_last_at    = UTC_TIMESTAMP(),
                     pmiss_last_ip    = VALUES(pmiss_last_ip),
                     pmiss_last_query = VALUES(pmiss_last_query),
                     pmiss_last_detail= VALUES(pmiss_last_detail)'
            )->execute([
                'r'  => $raison,
                // 0 et non NULL : dans un index UNIQUE, chaque NULL est
                // distinct, et l'agregation ne mordrait pas.
                'c'  => $campaignId ?? 0,
                'ip' => $ip,
                'q'  => mb_substr($query, 0, 1000),
                'd'  => $detail !== '' ? mb_substr($detail, 0, 255) : null,
            ]);
        } catch (Throwable $e) {
            error_log('[vigil/pb] journalisation du refus impossible : ' . $e->getMessage());
        }
    }
}
