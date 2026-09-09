<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Core\Crypto;
use PHPUnit\Framework\TestCase;

/**
 * Le secret TOTP est chiffre au repos : en clair, une lecture SQL suffirait a
 * fabriquer des codes valides pour n'importe quel compte.
 */
final class CryptoTest extends TestCase
{
    public function testAllerRetour(): void
    {
        $clair = 'PBRD2L4KVJCUIGDS65JSKQMBOJEHOQJL';

        self::assertSame($clair, Crypto::decrypt(Crypto::encrypt($clair)));
    }

    public function testLeChiffreNeContientPasLeClair(): void
    {
        $clair   = 'SECRETABCDEFGH234567';
        $chiffre = Crypto::encrypt($clair);

        self::assertStringNotContainsString($clair, $chiffre);
    }

    public function testNonceDifferentAChaqueAppel(): void
    {
        self::assertNotSame(Crypto::encrypt('x'), Crypto::encrypt('x'));
    }

    public function testMessageAltereRendNull(): void
    {
        $chiffre = Crypto::encrypt('secret');

        self::assertNull(Crypto::decrypt(substr($chiffre, 0, -1) . 'X'));
        self::assertNull(Crypto::decrypt('trop court'));
        self::assertNull(Crypto::decrypt(''));
    }
}
