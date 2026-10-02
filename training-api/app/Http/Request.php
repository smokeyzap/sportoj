<?php
declare(strict_types=1);

namespace App\Http;

final class Request
{
    /**
     * @param array<string,string> $query
     * @param array<string,string> $headers lower-cased names
     * @param array<string,string> $cookies
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $headers = [],
        public readonly array $cookies = [],
        public readonly string $body = '',
        public readonly string $ip = '0.0.0.0'
    ) {
    }

    /**
     * @param bool $trustProxyHeaders when the app runs behind a reverse proxy that you control, take the client IP from the
     *             LAST X-Forwarded-For hop (the one appended by that proxy). Never enable it without such a proxy: clients could spoof it.
     */
    public static function fromGlobals(bool $trustProxyHeaders = false): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_') && is_string($value)) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $k => $h) {
            if (isset($_SERVER[$k]) && is_string($_SERVER[$k])) {
                $headers[$h] = $_SERVER[$k];
            }
        }
        $query = [];
        foreach ($_GET as $k => $v) {
            if (is_string($v)) {
                $query[(string) $k] = $v;
            }
        }
        $cookies = [];
        foreach ($_COOKIE as $k => $v) {
            if (is_string($v)) {
                $cookies[(string) $k] = $v;
            }
        }
        $body = (string) file_get_contents('php://input', false, null, 0, 1_048_577);
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if ($trustProxyHeaders && isset($headers['x-forwarded-for'])) {
            $hops = array_map('trim', explode(',', $headers['x-forwarded-for']));
            $last = end($hops);
            if ($last !== false && filter_var($last, FILTER_VALIDATE_IP) !== false) {
                $ip = $last;
            }
        }
        return new self(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), $path, $query, $headers, $cookies, $body, $ip);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isSafe(): bool
    {
        return in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    /**
     * JSON object body; an empty body yields []. Anything else that is not a JSON object is a validation error.
     *
     * @return array<string,mixed>
     */
    public function jsonObject(): array
    {
        if (trim($this->body) === '') {
            return [];
        }
        $data = json_decode($this->body, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data) || ($data !== [] && array_is_list($data))) {
            throw ApiException::validation(['body' => 'Verzoek moet een geldig JSON-object zijn.']);
        }
        return $data;
    }
}
