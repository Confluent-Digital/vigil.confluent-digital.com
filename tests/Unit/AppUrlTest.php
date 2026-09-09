<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Core\AppUrl;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * `APP_URL` prefixe les liens de tracking. Un ecart avec l'adresse reellement
 * consultee produit des liens sur lesquels personne ne peut cliquer, sans la
 * moindre erreur visible — d'ou l'avertissement, et d'ou ces tests.
 */
final class AppUrlTest extends TestCase
{
    private function requete(string $url, array $entetes = [], string $ip = '172.18.0.1'): \Psr\Http\Message\ServerRequestInterface
    {
        $r = (new ServerRequestFactory())->createServerRequest('GET', $url, ['REMOTE_ADDR' => $ip]);
        foreach ($entetes as $k => $v) {
            $r = $r->withHeader($k, $v);
        }

        return $r;
    }

    protected function tearDown(): void
    {
        unset($_ENV['APP_URL']);
        parent::tearDown();
    }

    /**
     * Le cas rencontre en production : le nginx de l'hote termine le TLS et
     * relaie en clair vers le conteneur. PHP voit `http`, le visiteur est en
     * `https`, et l'avertissement d'ecart s'affichait en permanence.
     */
    public function testLeSchemaTransmisParLeRelaiEstPrisEnCompte(): void
    {
        $_ENV['APP_URL'] = 'https://vigil.confluent-digital.com';

        $r = $this->requete(
            'http://vigil.confluent-digital.com/app/campaigns/1',
            ['X-Forwarded-Proto' => 'https']
        );

        self::assertSame('https://vigil.confluent-digital.com', AppUrl::fromRequest($r));
        self::assertTrue(AppUrl::matchesRequest($r), 'aucun ecart ne doit etre signale');
    }

    /**
     * `X-Forwarded-Proto` est un en-tete que n'importe qui peut poser. Venant
     * d'une adresse publique, il ne vient pas de notre relai : on l'ignore.
     */
    public function testLeSchemaTransmisEstIgnoreDepuisUneAdressePublique(): void
    {
        $_ENV['APP_URL'] = 'https://vigil.confluent-digital.com';

        $r = $this->requete(
            'http://vigil.confluent-digital.com/app',
            ['X-Forwarded-Proto' => 'https'],
            '203.0.113.7'
        );

        self::assertSame('http://vigil.confluent-digital.com', AppUrl::fromRequest($r));
        self::assertFalse(AppUrl::matchesRequest($r), "l'ecart doit rester signale");
    }

    /** Sans relai, un site reellement en clair reste signale comme tel. */
    public function testUnSiteEnClairFaceAUnAppUrlHttpsEstSignale(): void
    {
        $_ENV['APP_URL'] = 'https://vigil.confluent-digital.com';

        $r = $this->requete('http://vigil.confluent-digital.com/app');

        self::assertFalse(AppUrl::matchesRequest($r));
        self::assertSame('http://vigil.confluent-digital.com', AppUrl::fromRequest($r));
    }

    /** `APP_URL` fait autorite : un lien de tracking ne se deduit pas du navigateur. */
    public function testAppUrlPrimeToujoursSurLaRequete(): void
    {
        $_ENV['APP_URL'] = 'https://vigil.confluent-digital.com';

        self::assertSame(
            'https://vigil.confluent-digital.com',
            AppUrl::base($this->requete('http://127.0.0.1:388/app'))
        );
    }

    public function testSansAppUrlOnRetombeSurLaRequete(): void
    {
        $_ENV['APP_URL'] = '';

        self::assertSame(
            'http://127.0.0.1:388',
            AppUrl::base($this->requete('http://127.0.0.1:388/app'))
        );
    }
}
