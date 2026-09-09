<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Core\PostbackAuth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sans authentification du postback, quiconque connait un clickid credite des
 * conversions — qui partent ensuite en S2S chez le partenaire et se facturent.
 * C'est la faille la plus couteuse du systeme.
 */
final class PostbackAuthTest extends TestCase
{
    public function testSecretCorrectAccepte(): void
    {
        self::assertTrue(PostbackAuth::check(
            ['campaign_postback_secret' => 'bon', 'campaign_postback_ips' => ''],
            'bon',
            '203.0.113.7'
        ));
    }

    public function testSecretFauxOuAbsentRefuse(): void
    {
        $campagne = ['campaign_postback_secret' => 'bon', 'campaign_postback_ips' => ''];

        self::assertSame('secret invalide', PostbackAuth::check($campagne, 'mauvais', null));
        self::assertSame('secret invalide', PostbackAuth::check($campagne, null, null));
        self::assertSame('secret invalide', PostbackAuth::check($campagne, '', null));
    }

    public function testWhitelistIpSeule(): void
    {
        $campagne = ['campaign_postback_secret' => '', 'campaign_postback_ips' => '203.0.113.7, 198.51.100.0/24'];

        self::assertTrue(PostbackAuth::check($campagne, null, '203.0.113.7'));
        self::assertTrue(PostbackAuth::check($campagne, null, '198.51.100.42'));
        self::assertStringStartsWith('ip non autorisee', (string) PostbackAuth::check($campagne, null, '203.0.113.8'));
        self::assertStringStartsWith('ip non autorisee', (string) PostbackAuth::check($campagne, null, null));
    }

    public function testLesDeuxControlesSontCumulatifs(): void
    {
        $campagne = ['campaign_postback_secret' => 'k', 'campaign_postback_ips' => '203.0.113.7'];

        self::assertTrue(PostbackAuth::check($campagne, 'k', '203.0.113.7'));
        self::assertIsString(PostbackAuth::check($campagne, 'k', '10.0.0.1'), 'bonne cle, mauvaise IP');
        self::assertIsString(PostbackAuth::check($campagne, 'x', '203.0.113.7'), 'bonne IP, mauvaise cle');
    }

    /**
     * Une campagne sans aucun controle laisse passer, volontairement, pour ne
     * pas couper un branchement en cours — mais le back-office doit la signaler
     * en alerte. Ce test fige ce compromis pour qu'il reste un choix conscient.
     */
    public function testCampagneSansControleLaissePasser(): void
    {
        self::assertTrue(PostbackAuth::check(
            ['campaign_postback_secret' => '', 'campaign_postback_ips' => ''],
            null,
            null
        ));
    }

    /** @return array<string, array{string, string, bool}> */
    public static function plages(): array
    {
        return [
            'hote exact'          => ['10.1.2.3', '10.1.2.3', true],
            'dans le /24'         => ['10.1.2.99', '10.1.2.0/24', true],
            'hors du /24'         => ['10.1.3.1', '10.1.2.0/24', false],
            'bord bas du /30'     => ['10.0.0.0', '10.0.0.0/30', true],
            'hors du /30'         => ['10.0.0.4', '10.0.0.0/30', false],
            '/32 exact'           => ['10.0.0.1', '10.0.0.1/32', true],
            '/0 tout'             => ['8.8.8.8', '0.0.0.0/0', true],
            'ipv6 exact'          => ['2001:db8::1', '2001:db8::1', true],
            'ipv6 dans le /32'    => ['2001:db8::99', '2001:db8::/32', true],
            'familles melangees'  => ['10.0.0.1', '2001:db8::/32', false],
            'masque aberrant'     => ['10.0.0.1', '10.0.0.0/99', false],
        ];
    }

    #[DataProvider('plages')]
    public function testAppartenanceAUnePlage(string $ip, string $cidr, bool $attendu): void
    {
        self::assertSame($attendu, PostbackAuth::ipAllowed($ip, $cidr));
    }

    /**
     * Le coeur du produit : **un seul postback pour toutes les campagnes d'un
     * client**. Le secret vivait uniquement sur la campagne, donc le `&s=`
     * differait de l'une a l'autre et le client se retrouvait a en configurer
     * autant qu'avant — le probleme qu'on resout reapparaissait un cran plus
     * bas.
     */
    public function testLeSecretDuClientVautPourToutesSesCampagnes(): void
    {
        $secretClient = 'cli-' . bin2hex(random_bytes(8));

        $campagneA = ['campaign_postback_secret' => null, 'client_postback_secret' => $secretClient];
        $campagneB = ['campaign_postback_secret' => null, 'client_postback_secret' => $secretClient];

        self::assertTrue(PostbackAuth::check($campagneA, $secretClient, '1.2.3.4'));
        self::assertTrue(PostbackAuth::check($campagneB, $secretClient, '1.2.3.4'));
        self::assertIsString(PostbackAuth::check($campagneA, 'autre-chose', '1.2.3.4'));
    }

    /**
     * Le secret de campagne reste accepte : c'est l'exception negociee, quand
     * un annonceur veut cloisonner une campagne. Les deux ouvrent.
     */
    public function testLeSecretDeCampagneResteAccepteAcoteDeCeluiDuClient(): void
    {
        $campagne = [
            'campaign_postback_secret' => 'propre-a-la-campagne',
            'client_postback_secret'   => 'commun-au-client',
        ];

        self::assertTrue(PostbackAuth::check($campagne, 'propre-a-la-campagne', '1.2.3.4'));
        self::assertTrue(PostbackAuth::check($campagne, 'commun-au-client', '1.2.3.4'));
        self::assertIsString(PostbackAuth::check($campagne, '', '1.2.3.4'));
        self::assertIsString(PostbackAuth::check($campagne, null, '1.2.3.4'));
    }

    /** Sans aucun secret ni filtre d'IP, on laisse passer — mais c'est une anomalie. */
    public function testAucunControleLaissePasser(): void
    {
        self::assertTrue(PostbackAuth::check(
            ['campaign_postback_secret' => null, 'client_postback_secret' => null],
            null,
            '1.2.3.4'
        ));
    }
}
