<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Core\Sandbox;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le bac a sable peut declencher un postback : c'est un outil de forge de
 * conversions avec une interface conviviale. Il ne doit pas exister en
 * production — pas « etre protege », ne pas exister.
 *
 * Ce test est le seul garde-fou automatique de cette promesse.
 */
final class SandboxGuardTest extends TestCase
{
    private ?string $initial = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initial = $_ENV['APP_ENV'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->initial === null) {
            unset($_ENV['APP_ENV']);
        } else {
            $_ENV['APP_ENV'] = $this->initial;
        }
        parent::tearDown();
    }

    /** @return array<string, array{string, bool}> */
    public static function environnements(): array
    {
        return [
            'production'  => ['production', false],
            'prod'        => ['prod', false],
            'staging'     => ['staging', false],
            'vide'        => ['', false],
            'inconnu'     => ['nimportequoi', false],
            'dev'         => ['dev', true],
            'development' => ['development', true],
            'local'       => ['local', true],
            'test'        => ['test', true],
            'casse mixte' => ['DEVELOPMENT', true],
        ];
    }

    #[DataProvider('environnements')]
    public function testDisponibiliteSelonLEnvironnement(string $env, bool $attendu): void
    {
        $_ENV['APP_ENV'] = $env;

        self::assertSame($attendu, Sandbox::isEnabled(), "APP_ENV=$env");
    }

    /**
     * La verification qui compte : ce n'est pas la constante qui protege, c'est
     * l'absence de route. On construit le routeur en production et on exige
     * qu'aucun chemin `/sandbox` n'y figure.
     */
    public function testAucuneRouteSandboxEnProduction(): void
    {
        $_ENV['APP_ENV'] = 'production';

        $app = \Slim\Factory\AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);

        $chemins = array_map(
            static fn ($route) => $route->getPattern(),
            $app->getRouteCollector()->getRoutes()
        );

        self::assertSame(
            [],
            array_values(array_filter($chemins, static fn (string $p): bool => str_contains($p, 'sandbox'))),
            'aucune route /sandbox ne doit etre enregistree en production'
        );
    }

    /**
     * Une fonctionnalite qu'on ne peut pas atteindre depuis l'interface n'existe
     * pas : le bac a sable est reste plusieurs iterations sans entree de menu,
     * et il fallait connaitre l'URL par coeur.
     */
    public function testLEntreeDeMenuSuitLaDisponibilite(): void
    {
        $gabarit = file_get_contents(dirname(__DIR__, 2) . '/src/Views/layouts/base.html.twig');

        self::assertStringContainsString(
            "'sandbox': {'url': '/sandbox'",
            (string) $gabarit,
            'le menu doit proposer une entree vers le bac a sable'
        );
        self::assertStringContainsString(
            'sandbox_enabled',
            (string) $gabarit,
            "l'entree doit etre conditionnee a la disponibilite, sinon elle pointe dans le vide en production"
        );
    }

    public function testLesRoutesExistentEnDeveloppement(): void
    {
        $_ENV['APP_ENV'] = 'development';

        $app = \Slim\Factory\AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);

        $chemins = array_map(
            static fn ($route) => $route->getPattern(),
            $app->getRouteCollector()->getRoutes()
        );
        $sandbox = array_filter($chemins, static fn (string $p): bool => str_contains($p, 'sandbox'));

        self::assertNotEmpty($sandbox);
        self::assertContains('/sandbox/platform', $sandbox);
    }
}
