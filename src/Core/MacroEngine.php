<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Substitution de macros dans les URLs de destination et de relai.
 *
 * C'est la brique qui permet de brancher une plateforme externe sans ecrire une
 * ligne de code : une destination se decrit entierement par un template et des
 * macros. Sans elle on retomberait sur une classe par plateforme, a maintenir
 * en parallele a chaque evolution.
 */
final class MacroEngine
{
    /** Macros de tracking : identifiants, montants, contexte technique. */
    public const TRACKING_MACROS = [
        'clickid', 'external_clickid', 'campaign_id', 'campaign_name',
        'publisher_id', 'publisher_token', 'sub1', 'sub2', 'sub3', 'sub4',
        'sub5', 'payout', 'revenue', 'currency', 'txid', 'status',
        'timestamp', 'datetime', 'ip', 'country', 'ua',
    ];

    // Cette liste sert DEUX rendus : la destination de campagne (rendue par
    // `c.php`) et la destination de relai (rendue par `PostbackRouter` avec le
    // contexte de `pb.php`). Une macro n'y a sa place que si les deux
    // l'alimentent — sinon elle marche d'un cote et part vide de l'autre, sans
    // erreur. C'etait le cas de `publisher_token` : servi par `c.php`, absent
    // du contexte de `pb.php`. La retirer aurait casse un usage qui fonctionne,
    // le formulaire de campagne rejetant alors une destination valide ; c'est
    // `pb.php` qui a ete complete.

    /**
     * Macros de pre-remplissage du kit mailing (cf. `Prefill`). Elles portent
     * de la donnee personnelle : elles transitent vers le money site, elles ne
     * sont jamais ecrites en base ni journalisees.
     *
     * `prefill` est un raccourci qui rend `&nom=X&prenom=Y` pour les douze
     * champs renseignes, quand le money site accepte nos noms de variables.
     */
    public const PREFILL_MACROS = [
        'civ', 'nom', 'prenom', 'email', 'cp', 'ville', 'pays',
        'jour', 'mois', 'annee', 'naissance', 'tel', 'prefill',
    ];

    public const MACROS = [
        ...self::TRACKING_MACROS,
        ...self::PREFILL_MACROS,
    ];

    /**
     * Rend le template. Toute valeur injectee est `rawurlencode`ee : une macro
     * qui contiendrait `&payout=999` ou un `#` casserait sinon l'URL, et
     * pourrait fabriquer des parametres que l'appelant n'a pas voulus.
     *
     * Une macro inconnue ou vide est remplacee par une chaine vide, jamais
     * laissee telle quelle : `{sub3}` en clair dans une URL partirait chez le
     * partenaire et polluerait son reporting.
     *
     * @param array<string, scalar|null> $context
     */
    public static function render(string $template, array $context): string
    {
        return (string) preg_replace_callback(
            '/\{([a-z0-9_]+)\}/i',
            static function (array $m) use ($context): string {
                $key = strtolower($m[1]);
                $value = $context[$key] ?? '';
                if ($value === null || $value === '') {
                    return '';
                }
                // `prefill` est deja un fragment de requete dont chaque VALEUR
                // a ete encodee par Prefill::queryFragment() : le re-encoder
                // transformerait ses `&` et ses `=` en %26 et %3D, et le money
                // site recevrait un seul parametre illisible.
                if ($key === 'prefill') {
                    return (string) $value;
                }
                return rawurlencode((string) $value);
            },
            $template
        );
    }

    /**
     * Macros presentes dans un template mais inconnues du moteur. Sert a
     * prevenir dans le back-office plutot qu'a laisser une URL partir amputee.
     *
     * @return string[]
     */
    public static function unknownMacros(string $template): array
    {
        preg_match_all('/\{([a-z0-9_]+)\}/i', $template, $m);
        $found = array_map('strtolower', $m[1] ?? []);

        return array_values(array_unique(array_diff($found, self::MACROS)));
    }
}
