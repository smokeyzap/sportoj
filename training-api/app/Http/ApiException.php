<?php
declare(strict_types=1);

namespace App\Http;

use RuntimeException;

/** An error that maps to the OpenAPI error envelope { "error": { code, message, details? } }. */
final class ApiException extends RuntimeException
{
    /** @param array<string,mixed>|null $details @param array<string,string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly ?array $details = null,
        public readonly array $headers = []
    ) {
        parent::__construct($message);
    }

    public static function unauthenticated(): self
    {
        return new self(401, 'UNAUTHENTICATED', 'Niet ingelogd.');
    }

    /** @param array<string,string> $fields */
    public static function validation(array $fields, string $message = 'Validatiefout.'): self
    {
        return new self(422, 'VALIDATION_ERROR', $message, ['fields' => $fields]);
    }

    public static function conflict(string $code, string $message): self
    {
        return new self(409, $code, $message);
    }

    public static function notFound(string $code, string $message): self
    {
        return new self(404, $code, $message);
    }

    public static function forbidden(): self
    {
        return new self(403, 'FORBIDDEN', 'Geen toegang tot deze resource.');
    }
}
