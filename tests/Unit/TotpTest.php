<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Core\Base32;
use App\Core\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    /** Secret d'exemple de la RFC : la chaine ASCII « 12345678901234567890 ». */
    private const SECRET_RFC = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /** @return array<string, array{int, string}> */
    public static function vecteursRfc6238(): array
    {
        // Annexe B de la RFC 6238, variante SHA-1. La RFC publie huit chiffres,
        // on compare les six de poids faible.
        return [
            't=59'          => [59, '287082'],
            't=1111111109'  => [1111111109, '081804'],
            't=1111111111'  => [1111111111, '050471'],
            't=1234567890'  => [1234567890, '005924'],
            't=2000000000'  => [2000000000, '279037'],
            't=20000000000' => [20000000000, '353130'],
        ];
    }

    /**
     * Le seul test qui prouve l'interoperabilite : si ces vecteurs passent, une
     * vraie application d'authentification acceptera nos codes.
     */
    #[DataProvider('vecteursRfc6238')]
    public function testVecteursOfficielsDeLaRfc(int $horodatage, string $attendu): void
    {
        self::assertSame($attendu, Totp::code(self::SECRET_RFC, intdiv($horodatage, 30)));
    }

    public function testSecretGenere(): void
    {
        $secret = Totp::generateSecret();

        self::assertSame(32, strlen($secret));
        self::assertSame(20, strlen(Base32::decode($secret)), '160 bits, taille recommandee');
    }

    public function testFenetreDeToleranceDUnPas(): void
    {
        $secret = Totp::generateSecret();
        $pas    = Totp::currentStep();

        self::assertSame($pas,     Totp::verify($secret, Totp::code($secret, $pas)));
        self::assertSame($pas - 1, Totp::verify($secret, Totp::code($secret, $pas - 1)));
        self::assertSame($pas + 1, Totp::verify($secret, Totp::code($secret, $pas + 1)));
        self::assertNull(Totp::verify($secret, Totp::code($secret, $pas - 2)), 'au-dela : refuse');
        self::assertNull(Totp::verify($secret, Totp::code($secret, $pas + 2)), 'au-dela : refuse');
    }

    /**
     * Sans memoire du pas consomme, un code intercepte reste utilisable
     * pendant toute sa fenetre de validite.
     */
    public function testUnCodeNEstAcceptableQuUneFois(): void
    {
        $secret = Totp::generateSecret();
        $code   = Totp::code($secret);

        $pas = Totp::verify($secret, $code);
        self::assertNotNull($pas);
        self::assertNull(Totp::verify($secret, $code, $pas), 'rejeu refuse');
        self::assertSame(
            $pas + 1,
            Totp::verify($secret, Totp::code($secret, $pas + 1), $pas),
            'le pas suivant reste accepte'
        );
    }

    public function testSaisiesMalformeesRefusees(): void
    {
        $secret = Totp::generateSecret();

        self::assertNull(Totp::verify($secret, '123'));
        self::assertNull(Totp::verify($secret, 'abcdef'));
        self::assertNull(Totp::verify($secret, ''));
    }

    public function testUriDEnrolement(): void
    {
        $secret = Totp::generateSecret();
        $uri    = Totp::provisioningUri($secret, 'admin@exemple.fr', 'Vigil');

        // Le libelle porte la date d'enrolement : une application
        // d'authentification ne sait pas qu'un ancien secret a ete revoque, et
        // deux entrees de meme nom cohabiteraient sans qu'on sache laquelle est
        // vivante.
        self::assertStringStartsWith('otpauth://totp/Vigil:admin%40exemple.fr%20', $uri);
        self::assertStringContainsString(gmdate('Y-m-d'), $uri);

        // La propriete qui compte : AUCUNE barre oblique dans le libelle, ni
        // litterale ni encodee. Le chemin d'une URI otpauth se decoupe sur
        // « / », et plusieurs applications le decoupent avant de decoder les
        // %2F — un libelle avec une date « 08/09/2026 » y casse l'import.
        $libelle = substr(explode('?', $uri)[0], strlen('otpauth://totp/'));
        self::assertStringNotContainsString('/', $libelle);
        self::assertStringNotContainsString('%2F', strtoupper($libelle));
        self::assertStringContainsString('secret=' . $secret, $uri);
        self::assertStringContainsString('digits=6', $uri);
        self::assertStringContainsString('period=30', $uri);
    }

    public function testBase32AllerRetourEtTolerance(): void
    {
        self::assertSame('12345678901234567890', Base32::decode(self::SECRET_RFC));
        self::assertSame(
            '12345678901234567890',
            Base32::decode(strtolower(chunk_split(self::SECRET_RFC, 4, ' '))),
            'espaces et minuscules toleres a la saisie manuelle'
        );
    }
}
