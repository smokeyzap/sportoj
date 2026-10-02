<?php
declare(strict_types=1);

namespace Tests\Acceptance;

use Tests\Support\ApiTestCase;
use Tests\Support\OpenApiValidator;
use Tests\Support\TestClient;

/** AT-140..AT-142. Every response in the whole suite is already validated by TestClient; this adds the structural checks. */
final class ContractTest extends ApiTestCase
{
    /** @return list<string> */
    private function specOperations(): array
    {
        $ops = [];
        foreach (OpenApiValidator::get()->document()['paths'] as $path => $item) {
            foreach (array_keys($item) as $method) {
                $ops[] = strtoupper($method) . ' ' . $path;
            }
        }
        sort($ops);
        return $ops;
    }

    public function testAT140RoutesAndSpecAreTheSameSet(): void
    {
        $routes = array_map(static fn (array $r) => $r['method'] . ' ' . $r['template'], $this->app->kernel()->router()->routes());
        sort($routes);
        self::assertSame($this->specOperations(), $routes, 'every implemented route is in openapi.yaml and vice versa');
        self::assertCount(23, $routes);
    }

    public function testAT142OnlyApiV1AndHealthExist(): void
    {
        foreach ($this->app->kernel()->router()->routes() as $r) {
            self::assertTrue($r['template'] === '/health' || str_starts_with($r['template'], '/api/v1/'), $r['template']);
        }
    }

    public function testAT140EveryOperationIsExercisedWithAConformingResponse(): void
    {
        TestClient::$covered = [];
        $c = $this->loggedIn();   // POST login
        $c->get('/health');
        $c->csrf = $c->get('/api/v1/auth/csrf')->at('data.csrf_token');   // rotates the token
        $c->get('/api/v1/me');
        $c->patch('/api/v1/me', ['name' => 'Naam']);
        $c->get('/api/v1/me/today');                       // onboarding
        $c->get('/api/v1/me/program');                     // 404 NO_ACTIVE_PROGRAM
        $c->get('/api/v1/me/history');
        $this->startProgram($c, 'position', 3, 1);
        $c->get('/api/v1/me/today');
        $c->get('/api/v1/me/program');

        $mon = $this->aid($c, 1, 3, 1);
        $c->get("/api/v1/workout-assignments/$mon");
        $this->act($c, $mon, 'start');
        $this->act($c, $mon, 'complete', ['notes' => 'ok']);
        $this->act($c, $mon, 'reopen');
        $this->act($c, $mon, 'skip');
        $this->act($c, $mon, 'reopen');
        $this->complete($c, $mon);
        $thu = $this->aid($c, 1, 3, 4);
        $this->complete($c, $thu);                          // deviation -> decision
        $c->post('/api/v1/me/program/continuation', ['mode' => 'program_sequence']);
        foreach ([2, 3, 5, 6] as $p) {
            $this->complete($c, $this->aid($c, 1, 3, $p));
        }
        $block = $this->programState($c)['blocks'][0]['block']['id'];
        $c->post("/api/v1/me/program/blocks/$block/extend");
        $this->completeCycle($c, 1, 4);
        $c->post("/api/v1/me/program/blocks/$block/advance");

        $tpl = $this->assignment($c, $mon)['workout']['id'];
        $c->get("/api/v1/workouts/$tpl");
        $s = $c->post("/api/v1/workouts/$tpl/sessions")->at('data.id');
        $c->post("/api/v1/workout-sessions/$s/complete", ['notes' => 'x']);
        $c->get('/api/v1/me/history?page=1&per_page=5');

        $d = $this->loggedIn('b@example.nl', 'B');
        $this->startProgram($d, 'position', 14, 1);
        $this->completeCycle($d, 5, 2);
        $d->post('/api/v1/me/program/complete');
        $d->post('/api/v1/me/program/restart', ['start_mode' => 'beginning']);
        $d->post('/api/v1/auth/logout');

        $missing = array_diff($this->specOperations(), array_keys(TestClient::$covered));
        self::assertSame([], array_values($missing), 'operations never exercised');
    }

    public function testAT141ErrorEnvelopeIsUniform(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $samples = [
            $this->anonymous()->login('x@example.nl', 'wrong'),
            $this->anonymous()->get('/api/v1/me'),
            $c->post('/api/v1/me/program/start', ['start_mode' => 'beginning']),
            $c->post('/api/v1/me/program/start', ['start_mode' => 'x']),
            $c->get('/api/v1/workout-assignments/01ARZ3NDEKTSV4RRFFQ69G5FAV'),
            $c->post('/api/v1/me/program/blocks/0J6147BJFBEFFJK2CH4114053K/advance'),
            $c->post('/api/v1/me/program/continuation', ['mode' => 'program_sequence']),
            $c->get('/api/v1/nope'),
            $c->request('DELETE', '/api/v1/me'),
            $c->post('/api/v1/auth/logout', null, ['X-CSRF-Token' => 'bad']),
        ];
        foreach ($samples as $r) {
            $j = $r->json();
            self::assertSame(['error'], array_keys($j), $r->body);
            self::assertContains(array_keys($j['error']), [['code', 'message'], ['code', 'message', 'details']], $r->body);
            self::assertMatchesRegularExpression('/^[A-Z][A-Z_]+$/', $j['error']['code']);
            self::assertNotSame('', $j['error']['message']);
            self::assertStringContainsString('application/json', (string) $r->header('Content-Type'));
        }
    }

    public function testEveryErrorCodeTheSpecDocumentsIsImplemented(): void
    {
        $codes = [];
        array_walk_recursive(OpenApiValidator::get()->document()['paths'], static function ($v, $k) use (&$codes): void {
            if ($k === 'code' && is_string($v)) {
                $codes[$v] = true;
            }
        });
        $source = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/app')) as $f) {
            if ($f->isFile() && str_ends_with((string) $f, '.php')) {
                $source .= file_get_contents((string) $f);
            }
        }
        self::assertGreaterThanOrEqual(18, count($codes));
        foreach (array_keys($codes) as $code) {
            self::assertStringContainsString("'$code'", $source, "documented error code $code is never produced");
        }
    }

    public function testSpecExamplesAreWellFormedErrorResponses(): void
    {
        foreach (OpenApiValidator::get()->document()['paths'] as $path => $item) {
            foreach ($item as $method => $op) {
                foreach ($op['responses'] as $status => $resp) {
                    $example = $resp['content']['application/json']['examples']['default']['value'] ?? null;
                    if ($example !== null) {
                        self::assertSame([], OpenApiValidator::get()->check(strtoupper($method), $this->materialise($path), (int) $status, json_encode($example)), "$method $path $status");
                    }
                }
            }
        }
    }

    private function materialise(string $template): string
    {
        return (string) preg_replace('/\{\w+\}/', '01ARZ3NDEKTSV4RRFFQ69G5FAV', $template);
    }

    public function testEveryRefInTheSpecResolves(): void
    {
        $doc = OpenApiValidator::get()->document();
        $count = 0;
        $walk = function (mixed $node) use (&$walk, &$count, $doc): void {
            if (!is_array($node)) {
                return;
            }
            foreach ($node as $k => $v) {
                if ($k === '$ref' && is_string($v)) {
                    $count++;
                    $target = $doc;
                    foreach (explode('/', substr($v, 2)) as $part) {
                        self::assertIsArray($target, "unresolvable $v");
                        self::assertArrayHasKey($part, $target, "unresolvable $v");
                        $target = $target[$part];
                    }
                } else {
                    $walk($v);
                }
            }
        };
        $walk($doc);
        self::assertGreaterThan(100, $count);
    }

    public function testKnownGapsAreWellFormed(): void
    {
        $known = require dirname(__DIR__) . '/known_contract_gaps.php';
        $spec = $this->specOperations();
        foreach ($known as $entry) {
            self::assertMatchesRegularExpression('/^([A-Z]+ \/\S*|\* \*) \d{3} [A-Z_-]+$/', $entry);
            [$method, $path] = explode(' ', $entry);
            if ($method !== '*') {
                self::assertContains("$method $path", $spec, "gap entry refers to an operation that is not in the spec: $entry");
            }
        }
        self::assertSame(array_values(array_unique($known)), $known, 'no duplicates');
    }
}
