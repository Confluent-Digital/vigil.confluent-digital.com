<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Core\MacroEngine;
use PHPUnit\Framework\TestCase;

final class MacroEngineTest extends TestCase
{
    /**
     * L'encodage n'est pas cosmetique : une valeur contenant `&` ou `#`
     * fabriquerait des parametres que l'appelant n'a pas voulus dans l'URL du
     * partenaire.
     */
    public function testToutesLesValeursSontEncodees(): void
    {
        $url = MacroEngine::render('https://x.test/?n={nom}&s={sub1}', [
            'nom'  => 'Dupont & Fils',
            'sub1' => 'a=1&b=2#frag',
        ]);

        self::assertSame('https://x.test/?n=Dupont%20%26%20Fils&s=a%3D1%26b%3D2%23frag', $url);
    }

    public function testMacroInconnueOuVideRendUneChaineVide(): void
    {
        self::assertSame(
            'https://x.test/?a=&b=',
            MacroEngine::render('https://x.test/?a={inconnue}&b={sub1}', ['sub1' => ''])
        );
    }

    public function testInsensibleALaCasse(): void
    {
        self::assertSame('X-42', MacroEngine::render('X-{CLICKID}', ['clickid' => '42']));
    }

    /**
     * `prefill` est la seule macro non re-encodee : ses valeurs le sont deja
     * une par une. L'encoder en bloc transformerait ses `&` et `=` en %26 et
     * %3D, et le money site recevrait un unique parametre illisible.
     */
    public function testPrefillNEstPasReEncodee(): void
    {
        $url = MacroEngine::render('https://x.test/?c=1{prefill}', [
            'prefill' => '&nom=Durand&prenom=Marie%20Claire',
        ]);

        self::assertSame('https://x.test/?c=1&nom=Durand&prenom=Marie%20Claire', $url);
    }

    public function testDetecteLesMacrosInconnues(): void
    {
        self::assertSame(
            ['nimportequoi'],
            MacroEngine::unknownMacros('https://x.test/?a={clickid}&b={nimportequoi}')
        );
        self::assertSame([], MacroEngine::unknownMacros('https://x.test/?a={clickid}&b={prenom}'));
    }

    public function testLesDouzeChampsDuKitMailingSontDesMacros(): void
    {
        foreach (['civ','nom','prenom','email','cp','ville','pays','jour','mois','annee','naissance','tel'] as $champ) {
            self::assertContains($champ, MacroEngine::MACROS, "{$champ} doit etre une macro");
        }
    }
}
