<?php
declare(strict_types=1);

namespace App\Http;

/** Route table. Path templates use {name} segments; the same templates must exist in openapi.yaml (tested). */
final class Router
{
    /** @var list<array{method:string,template:string,regex:string,names:list<string>,handler:callable,auth:bool,bucket:string}> */
    private array $routes = [];

    /** @param callable(Request,array<string,string>):Response $handler */
    public function add(string $method, string $template, callable $handler, bool $auth = true, string $bucket = 'api'): void
    {
        $names = [];
        $regex = preg_replace_callback('/\{(\w+)\}/', static function (array $m) use (&$names): string {
            $names[] = $m[1];
            return '([^/]+)';
        }, $template);
        $this->routes[] = [
            'method' => $method, 'template' => $template, 'regex' => '#^' . $regex . '$#',
            'names' => $names, 'handler' => $handler, 'auth' => $auth, 'bucket' => $bucket,
        ];
    }

    /**
     * @return array{route:array<string,mixed>,params:array<string,string>}
     * @throws ApiException 404 / 405
     */
    public function match(string $method, string $path): array
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $m) !== 1) {
                continue;
            }
            if ($route['method'] === $method || ($method === 'HEAD' && $route['method'] === 'GET')) {
                array_shift($m);
                return ['route' => $route, 'params' => array_combine($route['names'], $m) ?: []];
            }
            $allowed[] = $route['method'];
        }
        if ($allowed !== []) {
            throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Methode niet toegestaan.', null, ['Allow' => implode(', ', array_unique($allowed))]);
        }
        throw new ApiException(404, 'NOT_FOUND', 'Onbekend endpoint.');
    }

    /** @return list<array{method:string,template:string}> */
    public function routes(): array
    {
        return array_map(static fn (array $r) => ['method' => $r['method'], 'template' => $r['template']], $this->routes);
    }
}
