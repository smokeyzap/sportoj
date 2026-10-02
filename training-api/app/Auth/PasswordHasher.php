<?php
declare(strict_types=1);

namespace App\Auth;

/** Argon2id when available, bcrypt only as fallback (BR-174). */
final class PasswordHasher
{
    public static function algorithm(): string
    {
        return defined('PASSWORD_ARGON2ID') && in_array('argon2id', password_algos(), true)
            ? PASSWORD_ARGON2ID
            : PASSWORD_BCRYPT;
    }

    public function hash(string $password): string
    {
        return password_hash($password, self::algorithm());
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algorithm());
    }

    /** A hash to verify against when the account does not exist, so timing does not reveal existence. */
    public function dummyHash(): string
    {
        static $dummy = null;
        return $dummy ??= $this->hash(bin2hex(random_bytes(16)));
    }
}
