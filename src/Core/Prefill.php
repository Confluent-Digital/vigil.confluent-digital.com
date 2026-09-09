<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Pre-remplissage : les douze champs du kit mailing.
 *
 * L'affilie substitue [PRENOM], [EMAIL]... dans le lien qu'il diffuse, et les
 * valeurs doivent traverser Vigil jusqu'au formulaire du money site, pour qu'il
 * s'affiche deja rempli. Reference : docs/document_technique_kit_mailing.pdf,
 * page 8 (tableau filtres / variables).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CES VALEURS NE SONT JAMAIS ECRITES EN BASE.
 *
 * Ce sont des donnees directement identifiantes : nom, prenom, adresse
 * electronique, telephone, date de naissance, code postal, ville. La plateforme
 * externe que Vigil remplace ne les stocke pas non plus — le document technique
 * le dit explicitement : « La plateforme ne stock aucune information dans la
 * base. » Les persister ferait de Vigil un fichier de prospects, avec la
 * duree de conservation, la base legale et le registre de traitement que cela
 * suppose. Elles transitent, et rien de plus.
 *
 * Consequences a ne pas defaire :
 *  - `Prefill::stripFrom()` les retire de ce qui part dans `click_raw_query` ;
 *  - le journal nginx du chemin chaud n'enregistre pas la chaine de requete ;
 *  - `/c` renvoie `Referrer-Policy: no-referrer`, sinon l'URL complete — donc
 *    ces valeurs — partirait au money site dans l'en-tete `Referer`, puis dans
 *    ses propres journaux.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class Prefill
{
    /**
     * Les douze variables, dans l'ordre du document. La cle est le nom du
     * parametre attendu dans l'URL entrante ET le nom de la macro.
     *
     * @var array<string, string> nom de variable => libelle du filtre
     */
    public const FIELDS = [
        'civ'       => 'CIV',
        'nom'       => 'NOM',
        'prenom'    => 'PRENOM',
        'email'     => 'EMAIL',
        'cp'        => 'CP',
        'ville'     => 'VILLE',
        'pays'      => 'PAYS',
        'jour'      => 'JOUR',
        'mois'      => 'MOIS',
        'annee'     => 'ANNEE',
        'naissance' => 'NAISSANCE',
        'tel'       => 'TEL',
    ];

    /**
     * Alias tolerés en entree. Le document impose des noms precis, mais les
     * routeurs d'emailing en pratique n'ont pas tous la meme convention : on
     * accepte les variantes courantes plutot que de perdre silencieusement un
     * pre-remplissage.
     *
     * @var array<string, string> alias => variable canonique
     */
    private const ALIASES = [
        'civilite'   => 'civ',
        'lastname'   => 'nom',
        'firstname'  => 'prenom',
        'mail'       => 'email',
        'codepostal' => 'cp',
        'zip'        => 'cp',
        'city'       => 'ville',
        'country'    => 'pays',
        'day'        => 'jour',
        'month'      => 'mois',
        'year'       => 'annee',
        'birthday'   => 'naissance',
        'phone'      => 'tel',
        'telephone'  => 'tel',
    ];

    /** Longueur maximale acceptee par champ. */
    private const MAX_LENGTH = 190;

    /**
     * Extrait les valeurs de pre-remplissage d'une chaine de requete.
     *
     * @param  array<string, mixed> $query
     * @return array<string, string> variables canoniques renseignees uniquement
     */
    public static function extract(array $query): array
    {
        $out = [];

        foreach ($query as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $name = strtolower(trim((string) $key));
            $name = self::ALIASES[$name] ?? $name;

            if (!isset(self::FIELDS[$name])) {
                continue;
            }

            $clean = trim((string) $value);
            if ($clean === '') {
                continue;
            }

            // Les valeurs viennent de l'exterieur : on borne, et on retire les
            // caracteres de controle qui n'ont rien a faire dans une URL.
            $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $clean) ?? '';
            $out[$name] = ClickContext::truncate($clean, self::MAX_LENGTH);
        }

        return $out;
    }

    /**
     * Retire les champs de pre-remplissage d'un tableau de parametres.
     *
     * C'est ce qui empeche `click_raw_query` de devenir un fichier de
     * prospects : le JSON capte tout parametre inconnu, y compris ceux-la si
     * on ne les ecarte pas explicitement.
     *
     * @param  array<string, mixed> $query
     * @return array<string, mixed>
     */
    public static function stripFrom(array $query): array
    {
        return array_filter(
            $query,
            static function (string $key): bool {
                $name = strtolower(trim($key));
                $name = self::ALIASES[$name] ?? $name;

                return !isset(self::FIELDS[$name]);
            },
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Contexte de macros : toutes les variables, vides comprises.
     *
     * Une macro absente du contexte serait laissee telle quelle par certains
     * moteurs ; ici `MacroEngine` rend une chaine vide, mais on fournit les
     * douze cles pour que le comportement soit explicite.
     *
     * @param  array<string, string> $values
     * @return array<string, string>
     */
    public static function macroContext(array $values): array
    {
        $context = [];
        foreach (array_keys(self::FIELDS) as $name) {
            $context[$name] = $values[$name] ?? '';
        }

        return $context;
    }

    /**
     * Fragment de requete pret a concatener : `&nom=X&prenom=Y`.
     *
     * Sert la macro `{prefill}`, pour les campagnes dont le money site accepte
     * nos noms de variables tels quels — on evite alors d'ecrire les douze
     * macros a la main dans l'URL de destination.
     *
     * @param array<string, string> $values
     */
    public static function queryFragment(array $values): string
    {
        $parts = [];
        foreach (array_keys(self::FIELDS) as $name) {
            if (($values[$name] ?? '') !== '') {
                $parts[] = $name . '=' . rawurlencode($values[$name]);
            }
        }

        return $parts === [] ? '' : '&' . implode('&', $parts);
    }
}
