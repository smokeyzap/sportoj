<?php
declare(strict_types=1);

namespace App\Support;

/** ULID generator (48-bit ms timestamp + 80 bits randomness, Crockford base32). BR-183. */
final class Ulid
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    public const PATTERN = '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/';

    public static function generate(?int $millis = null): string
    {
        $millis ??= (int) floor(microtime(true) * 1000);
        $time = '';
        for ($i = 0; $i < 10; $i++) {
            $time = self::ALPHABET[$millis % 32] . $time;
            $millis = intdiv($millis, 32);
        }
        $bytes = random_bytes(10);
        $bits = '';
        foreach (str_split($bytes) as $b) {
            $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        }
        $rand = '';
        for ($i = 0; $i < 16; $i++) {
            $rand .= self::ALPHABET[bindec(substr($bits, $i * 5, 5))];
        }
        return $time . $rand;
    }

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }
}
