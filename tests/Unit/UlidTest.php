<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Core\Ulid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UlidTest extends TestCase
{
    public function testAllerRetourBinaire(): void
    {
        $ulid = Ulid::generate();

        self::assertSame(26, strlen($ulid));
        self::assertSame(16, strlen(Ulid::toBinary($ulid)), 'BINARY(16) en base');
        self::assertSame($ulid, Ulid::fromBinary(Ulid::toBinary($ulid)));
    }

    /**
     * L'horodatage relisible n'est pas un detail : c'est lui qui permet a
     * `pb.php` de borner `click_date` et d'elaguer les partitions. Sans lui,
     * chercher un clic scannerait toute la table.
     */
    public function testHorodatageRelisibleDepuisLIdentifiant(): void
    {
        $ms = (int) (microtime(true) * 1000);

        self::assertSame($ms, Ulid::timestamp(Ulid::generate($ms)));
    }

    public function testMonotoneDansLaMemeMilliseconde(): void
    {
        $ms = (int) (microtime(true) * 1000);

        $suite = [];
        for ($i = 0; $i < 500; $i++) {
            $suite[] = Ulid::generate($ms);
        }

        $trie = $suite;
        sort($trie);

        self::assertSame($trie, $suite, 'l\'ordre de generation doit etre l\'ordre lexicographique');
        self::assertCount(500, array_unique($suite));
    }

    public function testCroissantDansLeTemps(): void
    {
        $ms = (int) (microtime(true) * 1000);

        self::assertLessThan(Ulid::generate($ms + 1), Ulid::generate($ms));
    }

    /** @return array<string, array{string}> */
    public static function entreesInvalides(): array
    {
        return [
            'trop court'          => ['ABC'],
            'trop long'           => [str_repeat('A', 27)],
            'hors alphabet (U)'   => [str_repeat('U', 26)],
            'hors alphabet (I)'   => [str_repeat('I', 26)],
            'vide'                => [''],
        ];
    }

    #[DataProvider('entreesInvalides')]
    public function testRejetteLesEntreesInvalides(string $entree): void
    {
        self::assertFalse(Ulid::isValid($entree));
        self::assertNull(Ulid::toBinary($entree));
        self::assertNull(Ulid::timestamp($entree));
    }

    public function testUniciteSurVolume(): void
    {
        $vus = [];
        for ($i = 0; $i < 20000; $i++) {
            $vus[Ulid::generate()] = true;
        }

        self::assertCount(20000, $vus);
    }
}
