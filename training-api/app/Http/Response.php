<?php
declare(strict_types=1);

namespace App\Http;

final class Response
{
    /** @var list<string> */
    private array $cookies = [];

    /** @param array<string,string> $headers */
    public function __construct(public int $status = 200, public ?string $body = null, public array $headers = [])
    {
    }

    public static function json(int $status, mixed $payload): self
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        return new self($status, $json, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function noContent(): self
    {
        return new self(204);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function addCookie(string $setCookieLine): self
    {
        $this->cookies[] = $setCookieLine;
        return $this;
    }

    /** @return list<string> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header($k . ': ' . $v);
        }
        foreach ($this->cookies as $c) {
            header('Set-Cookie: ' . $c, false);
        }
        if ($this->body !== null && $this->status !== 204) {
            echo $this->body;
        }
    }
}
