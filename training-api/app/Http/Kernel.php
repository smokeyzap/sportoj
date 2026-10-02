<?php
declare(strict_types=1);

namespace App\Http;

use App\Application;
use App\Auth\AuthContext;
use App\Controllers\AssignmentController;
use App\Controllers\AuthController;
use App\Controllers\HealthController;
use App\Controllers\MeController;
use App\Controllers\WorkoutController;

/** Routing, authentication, rate limiting, CSRF, CORS and the error envelope. */
final class Kernel
{
    private Router $router;

    public function __construct(private Application $app)
    {
        $this->router = new Router();
        $this->routes();
    }

    public function router(): Router
    {
        return $this->router;
    }

    private function routes(): void
    {
        $r = $this->router;
        $health = new HealthController($this->app);
        $auth = new AuthController($this->app);
        $me = new MeController($this->app);
        $as = new AssignmentController($this->app);
        $wo = new WorkoutController($this->app);

        $r->add('GET', '/health', fn (Request $q) => $health->show(), false);

        $r->add('POST', '/api/v1/auth/login', fn (Request $q) => $auth->login($q), false);
        $r->add('GET', '/api/v1/auth/csrf', fn (Request $q, array $p, AuthContext $c) => $auth->csrf($q, $c));
        $r->add('POST', '/api/v1/auth/logout', fn (Request $q, array $p, AuthContext $c) => $auth->logout($q, $c));

        $r->add('GET', '/api/v1/me', fn (Request $q, array $p, AuthContext $c) => $me->show($q, $c));
        $r->add('PATCH', '/api/v1/me', fn (Request $q, array $p, AuthContext $c) => $me->update($q, $c));
        $r->add('GET', '/api/v1/me/today', fn (Request $q, array $p, AuthContext $c) => $me->today($q, $c));
        $r->add('GET', '/api/v1/me/program', fn (Request $q, array $p, AuthContext $c) => $me->program($q, $c));
        $r->add('GET', '/api/v1/me/history', fn (Request $q, array $p, AuthContext $c) => $me->history($q, $c));
        $r->add('POST', '/api/v1/me/program/start', fn (Request $q, array $p, AuthContext $c) => $me->start($q, $c));
        $r->add('POST', '/api/v1/me/program/restart', fn (Request $q, array $p, AuthContext $c) => $me->start($q, $c));
        $r->add('POST', '/api/v1/me/program/continuation', fn (Request $q, array $p, AuthContext $c) => $me->continuation($q, $c));
        $r->add('POST', '/api/v1/me/program/complete', fn (Request $q, array $p, AuthContext $c) => $me->complete($q, $c));
        $r->add('POST', '/api/v1/me/program/blocks/{block_id}/extend', fn (Request $q, array $p, AuthContext $c) => $me->extend($q, $p, $c));
        $r->add('POST', '/api/v1/me/program/blocks/{block_id}/advance', fn (Request $q, array $p, AuthContext $c) => $me->advance($q, $p, $c));

        $r->add('GET', '/api/v1/workout-assignments/{assignment_id}', fn (Request $q, array $p, AuthContext $c) => $as->show($q, $p, $c));
        $r->add('POST', '/api/v1/workout-assignments/{assignment_id}/start', fn (Request $q, array $p, AuthContext $c) => $as->start($q, $p, $c));
        $r->add('POST', '/api/v1/workout-assignments/{assignment_id}/complete', fn (Request $q, array $p, AuthContext $c) => $as->complete($q, $p, $c));
        $r->add('POST', '/api/v1/workout-assignments/{assignment_id}/skip', fn (Request $q, array $p, AuthContext $c) => $as->skip($q, $p, $c));
        $r->add('POST', '/api/v1/workout-assignments/{assignment_id}/reopen', fn (Request $q, array $p, AuthContext $c) => $as->reopen($q, $p, $c));

        $r->add('GET', '/api/v1/workouts/{workout_id}', fn (Request $q, array $p, AuthContext $c) => $wo->show($q, $p, $c));
        $r->add('POST', '/api/v1/workouts/{workout_id}/sessions', fn (Request $q, array $p, AuthContext $c) => $wo->startSession($q, $p, $c));
        $r->add('POST', '/api/v1/workout-sessions/{session_id}/complete', fn (Request $q, array $p, AuthContext $c) => $wo->completeSession($q, $p, $c));
    }

    public function handle(Request $request): Response
    {
        try {
            $response = $this->dispatch($request);
        } catch (ApiException $e) {
            $response = $this->errorResponse($e);
            if ($e->status === 401 && isset($request->cookies[$this->app->sessions()->cookieName()])) {
                $response->addCookie($this->app->sessions()->expiredCookieHeader());
            }
        } catch (\Throwable $e) {
            $this->app->logger()->error('unhandled exception', ['type' => $e::class, 'message' => $e->getMessage(), 'at' => $e->getFile() . ':' . $e->getLine()]);
            $details = $this->app->config->debug() ? ['exception' => $e::class, 'message' => $e->getMessage()] : null;
            $response = $this->errorResponse(new ApiException(500, 'INTERNAL_ERROR', 'Er is een onverwachte fout opgetreden.', $details));
        }
        return $this->decorate($request, $response);
    }

    private function dispatch(Request $request): Response
    {
        if ($request->method === 'OPTIONS') {
            return Response::noContent();   // preflight; CORS headers are added by decorate() for allowed origins only
        }
        $match = $this->router->match($request->method, $request->path);
        $route = $match['route'];

        $ctx = null;
        if ($route['auth']) {
            $cookie = $request->cookies[$this->app->sessions()->cookieName()] ?? null;
            $ctx = $this->app->sessions()->authenticate($cookie);
            if ($ctx === null) {
                throw ApiException::unauthenticated();
            }
            $this->rateLimit($request, $ctx);
            if (!$request->isSafe() && !$this->app->sessions()->verifyCsrf($ctx, $request->header('X-CSRF-Token'))) {
                throw new ApiException(419, 'CSRF_MISMATCH', 'CSRF-token ongeldig of verlopen.');
            }
        }
        /** @var callable $handler */
        $handler = $route['handler'];
        return $route['auth'] ? $handler($request, $match['params'], $ctx) : $handler($request, $match['params']);
    }

    private function rateLimit(Request $request, AuthContext $ctx): void
    {
        $cfg = $this->app->config;
        $limiter = $this->app->rateLimiter();
        $apiMax = $cfg->int('RATE_API_MAX', 120);
        $mutMax = $cfg->int('RATE_MUTATION_MAX', 60);
        $ok = $limiter->attempt('api:' . $ctx->tokenHash, $apiMax, 60);
        if ($ok && !$request->isSafe()) {
            $ok = $limiter->attempt('mut:' . $ctx->tokenHash, $mutMax, 60);
        }
        if (!$ok) {
            throw new ApiException(429, 'RATE_LIMITED', 'Te veel verzoeken.', null, ['Retry-After' => '60']);
        }
    }

    private function errorResponse(ApiException $e): Response
    {
        $error = ['code' => $e->errorCode, 'message' => $e->getMessage()];
        if ($e->details !== null) {
            $error['details'] = $e->details;
        }
        $res = Response::json($e->status, ['error' => $error]);
        foreach ($e->headers as $k => $v) {
            $res->withHeader($k, $v);
        }
        return $res;
    }

    /** Security headers and explicit-origin credentialed CORS (AT-143). Never a wildcard. */
    private function decorate(Request $request, Response $response): Response
    {
        $response->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer');
        $origin = $request->header('Origin');
        $response->withHeader('Vary', 'Origin');
        if ($origin !== null && in_array($origin, $this->app->config->list('FRONTEND_ORIGINS'), true)) {
            $response->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Access-Control-Allow-Credentials', 'true');
            if ($request->method === 'OPTIONS') {
                $response->withHeader('Access-Control-Allow-Methods', 'GET, POST, PATCH, OPTIONS')
                    ->withHeader('Access-Control-Allow-Headers', 'Content-Type, X-CSRF-Token')
                    ->withHeader('Access-Control-Max-Age', '600');
            }
        }
        return $response;
    }
}
