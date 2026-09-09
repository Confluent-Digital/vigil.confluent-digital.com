<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Empile les relais d'une conversion vers les destinations de sa campagne.
 *
 * N'envoie RIEN : c'est PostbackFlushTask qui appelle. Un appel HTTP sortant
 * dans la requete du money site ferait timeouter le money site des que la
 * plateforme externe ralentit.
 */
final class PostbackRouter
{
    /**
     * @param  array<string, mixed> $context macros disponibles pour les templates
     * @return int nombre de relais empiles
     */
    public static function enqueue(
        PDO $pdo,
        int $conversionId,
        int $campaignId,
        ?int $publisherId,
        string $status,
        array $context
    ): int {
        // Trois portees, lues d'une seule requete :
        //
        //   campagne NULL + publisher renseigne  -> le pixel du publisher, sur
        //                                           toutes ses conversions
        //   campagne renseignee + publisher NULL -> la destination de cette
        //                                           campagne, toutes sources
        //   les deux renseignes                  -> ce couple precis
        //
        // Le dernier predicat exclut la ligne aux deux colonnes nulles : elle
        // se declencherait sur tout. Une contrainte CHECK l'interdit deja en
        // base, on ne s'y fie pas seule — une migration future pourrait la
        // retirer sans que ce code s'en apercoive.
        $sql = <<<'SQL'
            SELECT cpb_id, cpb_url, cpb_method, cpb_body, cpb_headers, cpb_on_status
              FROM t_campaign_postback
             WHERE cpb_status = 'active'
               AND (cpb_id_campaign  IS NULL OR cpb_id_campaign  = :campaign)
               AND (cpb_id_publisher IS NULL OR cpb_id_publisher = :publisher)
               AND (cpb_id_campaign IS NOT NULL OR cpb_id_publisher IS NOT NULL)
        SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['campaign' => $campaignId, 'publisher' => $publisherId]);

        $insert = $pdo->prepare(
            'INSERT INTO t_postback_queue
                 (pq_id_conversion, pq_id_campaign_postback, pq_url, pq_method,
                  pq_body, pq_headers, pq_next_try_at)
             VALUES (:conversion, :destination, :url, :method, :body, :headers, UTC_TIMESTAMP())'
        );

        // Garde-fou contre la course : deux postbacks simultanes peuvent tous
        // deux conclure « conversion nouvelle » avant que l'un ait ecrit. On
        // refuse alors d'empiler un relai strictement identique deja en attente
        // ou deja parti. Un rejeu manuel, lui, remet les lignes existantes en
        // `pending` sans en creer de nouvelles : il n'est pas bloque.
        $alreadyQueued = $pdo->prepare(
            "SELECT 1 FROM t_postback_queue
              WHERE pq_id_conversion = :conversion
                AND pq_id_campaign_postback = :destination
                AND pq_url = :url
                AND pq_status IN ('pending', 'sent')
              LIMIT 1"
        );

        $count = 0;
        foreach ($stmt->fetchAll() as $destination) {
            if (!self::triggersOn((string) $destination['cpb_on_status'], $status)) {
                continue;
            }

            $url = MacroEngine::render((string) $destination['cpb_url'], $context);

            $alreadyQueued->execute([
                'conversion'  => $conversionId,
                'destination' => $destination['cpb_id'],
                'url'         => $url,
            ]);
            if ($alreadyQueued->fetchColumn() !== false) {
                continue;
            }

            $insert->execute([
                'conversion'  => $conversionId,
                'destination' => $destination['cpb_id'],
                // Macros resolues des maintenant : le worker n'a plus a
                // reconstruire le contexte, et on garde la trace exacte de ce
                // qui a ete demande, meme si la campagne change ensuite.
                'url'     => $url,
                'method'  => $destination['cpb_method'],
                'body'    => $destination['cpb_body'] !== null
                    ? MacroEngine::render((string) $destination['cpb_body'], $context)
                    : null,
                'headers' => $destination['cpb_headers'],
            ]);
            $count++;
        }

        return $count;
    }

    /** Le CSV `cpb_on_status` contient-il ce statut ? */
    public static function triggersOn(string $csv, string $status): bool
    {
        $list = array_filter(array_map('trim', explode(',', strtolower($csv))));

        return $list === [] ? $status === 'approved' : in_array(strtolower($status), $list, true);
    }
}
