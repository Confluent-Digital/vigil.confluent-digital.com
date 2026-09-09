<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Core\Prefill;
use PHPUnit\Framework\TestCase;

/**
 * Le pre-remplissage porte de la donnee directement identifiante. Ces tests
 * verrouillent la promesse centrale : elle traverse, elle n'est pas conservee.
 */
final class PrefillTest extends TestCase
{
    private const ECHANTILLON = [
        'civ' => 'Mme', 'nom' => 'Durand', 'prenom' => 'Marie Claire',
        'email' => 'marie.durand@exemple.fr', 'cp' => '75011', 'ville' => 'Paris',
        'pays' => 'FR', 'jour' => '14', 'mois' => '07', 'annee' => '1982',
        'naissance' => '14/07/1982', 'tel' => '0612345678',
    ];

    public function testLesDouzeChampsDuDocumentSontReconnus(): void
    {
        self::assertCount(12, Prefill::FIELDS);
        self::assertSame(self::ECHANTILLON, Prefill::extract(self::ECHANTILLON));
    }

    /**
     * L'invariant a ne jamais defaire : sans ce filtre, `click_raw_query`
     * deviendrait un fichier de prospects — nom, courriel, telephone et date de
     * naissance, conserves sans duree ni base legale.
     */
    public function testRienDeCeQuiIdentifieNeSurvitAuFiltre(): void
    {
        $restant = Prefill::stripFrom(self::ECHANTILLON + [
            'utm_source' => 'newsletter',
            'gclid'      => 'xyz',
        ]);

        self::assertSame(['utm_source' => 'newsletter', 'gclid' => 'xyz'], $restant);

        $serialise = json_encode($restant, JSON_UNESCAPED_UNICODE);
        foreach (['Durand', 'Marie', 'marie.durand', '0612345678', '75011', '1982'] as $donnee) {
            self::assertStringNotContainsString($donnee, (string) $serialise);
        }
    }

    public function testLeFiltreEcarteAussiLesAlias(): void
    {
        self::assertSame(
            ['utm_source' => 'nl'],
            Prefill::stripFrom(['firstname' => 'Marie', 'phone' => '06', 'utm_source' => 'nl'])
        );
    }

    /**
     * Les routeurs d'emailing n'ont pas tous la meme convention : un
     * pre-remplissage perdu en silence serait pire qu'un alias de trop.
     */
    public function testAliasCourantsAcceptes(): void
    {
        $valeurs = Prefill::extract([
            'firstname' => 'Marie', 'lastname' => 'Durand',
            'mail' => 'm@d.fr', 'zip' => '75011', 'phone' => '0612345678',
        ]);

        self::assertSame('Marie', $valeurs['prenom']);
        self::assertSame('Durand', $valeurs['nom']);
        self::assertSame('m@d.fr', $valeurs['email']);
        self::assertSame('75011', $valeurs['cp']);
        self::assertSame('0612345678', $valeurs['tel']);
    }

    public function testValeursVidesEtNonScalairesIgnorees(): void
    {
        self::assertSame([], Prefill::extract(['nom' => '', 'prenom' => '   ', 'email' => ['x']]));
    }

    public function testValeursBorneesEtNettoyees(): void
    {
        $valeurs = Prefill::extract(['nom' => str_repeat('a', 400), 'ville' => "Pa\x00ris\x1F"]);

        self::assertSame(190, mb_strlen($valeurs['nom']), 'STRICT_TRANS_TABLES rejette au-dela');
        self::assertSame('Paris', $valeurs['ville']);
    }

    public function testFragmentDeRequeteEncodeChaqueValeur(): void
    {
        $fragment = Prefill::queryFragment(['prenom' => 'Marie Claire', 'nom' => 'Dupont & Fils']);

        self::assertSame('&nom=Dupont%20%26%20Fils&prenom=Marie%20Claire', $fragment);
        self::assertSame('', Prefill::queryFragment([]));
    }

    public function testContexteDeMacrosToujoursComplet(): void
    {
        $contexte = Prefill::macroContext(['nom' => 'Durand']);

        self::assertCount(12, $contexte);
        self::assertSame('Durand', $contexte['nom']);
        self::assertSame('', $contexte['email'], 'une macro non renseignee vaut vide, pas absente');
    }
}
