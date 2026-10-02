<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Ulid;
use PHPUnit\Framework\TestCase;

final class UlidTest extends TestCase
{
    public function testFormatAndUniqueness(): void
    {
        $seen = [];
        for ($i = 0; $i < 2000; $i++) {
            $id = Ulid::generate();
            self::assertSame(1, preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $id), $id);
            self::assertTrue(Ulid::isValid($id));
            $seen[$id] = true;
        }
        self::assertCount(2000, $seen);
    }

    public function testTimeOrdering(): void
    {
        self::assertLessThan(Ulid::generate(1_700_000_000_001), Ulid::generate(1_700_000_000_000), 'an earlier ULID sorts first');
        self::assertSame('01ARYZ6S41', substr(Ulid::generate(1469918176385), 0, 10), 'matches the timestamp part of the ULID spec reference example');
    }

    public function testRejectsInvalid(): void
    {
        foreach (['', '123', 'ILOU' . str_repeat('A', 22), '81ARZ3NDEKTSV4RRFFQ69G5FAV', '01ARZ3NDEKTSV4RRFFQ69G5FA', '01arz3ndektsv4rrffq69g5fav'] as $bad) {
            self::assertFalse(Ulid::isValid($bad), $bad);
        }
    }

    public function testSeedIdsAreValidUlids(): void
    {
        $seed = (string) file_get_contents(dirname(__DIR__, 2) . '/database/seed.sql');
        preg_match_all("/'([0-9A-Z]{26})'/", $seed, $m);
        self::assertGreaterThan(150, count($m[1]));
        foreach (array_unique($m[1]) as $id) {
            self::assertTrue(Ulid::isValid($id), $id);
        }
    }
}
