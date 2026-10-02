<?php
declare(strict_types=1);

namespace Tests\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Symfony\Component\Yaml\Yaml;

/** Validates real responses against docs/spec/openapi.yaml (AT-140). */
final class OpenApiValidator
{
    private const ID = 'https://trainingsapp.test/openapi';
    private static ?self $instance = null;
    private Validator $validator;
    /** @var array<string,mixed> */
    private array $doc;

    public static function get(): self
    {
        return self::$instance ??= new self(dirname(__DIR__, 3) . '/docs/spec/openapi.yaml');
    }

    private function __construct(string $file)
    {
        $this->doc = Yaml::parseFile($file);
        $object = json_decode((string) json_encode($this->doc));
        $object->{'$id'} = self::ID;
        $this->validator = new Validator();
        $this->validator->resolver()->registerRaw($object);
    }

    /** @return array<string,mixed> */
    public function document(): array
    {
        return $this->doc;
    }

    /** @return array{0:string,1:array<string,mixed>}|null template + path item */
    public function matchPath(string $path): ?array
    {
        foreach ($this->doc['paths'] as $template => $item) {
            $regex = '#^' . preg_replace('/\\\\\{\w+\\\\\}/', '[^/]+', preg_quote($template, '#')) . '$#';
            if (preg_match($regex, $path) === 1) {
                return [$template, $item];
            }
        }
        return null;
    }

    /**
     * @return list<string> problems (empty = conforms). 'UNDOCUMENTED:<METHOD> <template> <status>' marks a response status the spec lacks.
     */
    public function check(string $method, string $path, int $status, ?string $body): array
    {
        $m = $this->matchPath($path);
        if ($m === null) {
            return ["unknown path $path"];
        }
        [$template, $item] = $m;
        $op = $item[strtolower($method)] ?? null;
        if ($op === null) {
            return ["UNDOCUMENTED:$method $template $status"];
        }
        $resp = $op['responses'][(string) $status] ?? null;
        if ($resp === null) {
            return ["UNDOCUMENTED:$method $template $status"];
        }
        $documentedCode = $resp['content']['application/json']['examples']['default']['value']['error']['code'] ?? null;
        $actual = json_decode((string) $body, true);
        $actualCode = is_array($actual) ? ($actual['error']['code'] ?? null) : null;
        if ($documentedCode !== null && $actualCode !== null && $actualCode !== $documentedCode) {
            // The status is documented but with a different error code than the one the spec shows for it.
            return ["UNDOCUMENTED:$method $template $status"];
        }
        $schema = $resp['content']['application/json']['schema'] ?? null;
        if ($schema === null) {
            return $body === null || $body === '' ? [] : ["$method $template $status documents no body but one was sent"];
        }
        $data = json_decode((string) $body);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ["$method $template $status body is not JSON"];
        }
        $ref = $schema['$ref'] ?? null;
        $result = $ref !== null
            ? $this->validator->validate($data, self::ID . $ref)
            : $this->validator->validate($data, json_decode((string) json_encode($schema)));
        if ($result->isValid()) {
            return [];
        }
        $lines = (new ErrorFormatter())->formatFlat($result->error());
        return ["$method $template $status schema violation: " . implode(' | ', array_slice($lines, 0, 6))];
    }
}
