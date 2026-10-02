<?php
declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/** All persisted timestamps are UTC (BR-180). The clock is injectable so tests can move time. */
class Clock
{
    private static ?DateTimeImmutable $frozen = null;

    public static function freeze(?DateTimeImmutable $at): void
    {
        self::$frozen = $at;
    }

    public function now(): DateTimeImmutable
    {
        return self::$frozen ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public static function toDb(DateTimeImmutable $t): string
    {
        return $t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    public static function fromDb(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    public static function toIso(?string $dbValue): ?string
    {
        return $dbValue === null ? null : self::fromDb($dbValue)->format('Y-m-d\TH:i:s\Z');
    }
}
