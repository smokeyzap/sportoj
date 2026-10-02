<?php
declare(strict_types=1);

namespace App\Auth;

/** The authenticated principal for the current request. */
final class AuthContext
{
    public function __construct(
        public readonly int $userId,
        public readonly string $userPublicId,
        public readonly int $sessionId,
        public readonly string $tokenHash,
        public readonly string $csrfTokenHash
    ) {
    }
}
