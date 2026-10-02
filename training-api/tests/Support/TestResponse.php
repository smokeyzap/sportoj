<?php
declare(strict_types=1);

namespace Tests\Support;

final class TestResponse
{
    /** @param array<string,string> $headers @param list<string> $cookies */
    public function __construct(public readonly int $status, public readonly ?string $body, public readonly array $headers, public readonly array $cookies)
    {
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        $d = json_decode((string) $this->body, true);
        return is_array($d) ? $d : [];
    }

    /** @return mixed value at a dotted path of the decoded JSON */
    public function at(string $path): mixed
    {
        $v = $this->json();
        foreach (explode('.', $path) as $k) {
            if (!is_array($v) || !array_key_exists($k, $v)) {
                return null;
            }
            $v = $v[$k];
        }
        return $v;
    }

    public function data(): mixed
    {
        return $this->json()['data'] ?? null;
    }

    public function errorCode(): ?string
    {
        return $this->json()['error']['code'] ?? null;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $k => $v) {
            if (strcasecmp($k, $name) === 0) {
                return $v;
            }
        }
        return null;
    }
}
