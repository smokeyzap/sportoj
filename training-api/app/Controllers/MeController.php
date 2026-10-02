<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Application;
use App\Auth\AuthContext;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Presenters\Presenter;

final class MeController
{
    public function __construct(private Application $app)
    {
    }

    public function show(Request $r, AuthContext $ctx): Response
    {
        return Response::json(200, ['data' => Presenter::user($this->user($ctx))]);
    }

    public function update(Request $r, AuthContext $ctx): Response
    {
        $in = $r->jsonObject();
        $fields = [];
        $errors = [];
        if (array_key_exists('name', $in)) {
            $name = is_string($in['name']) ? trim($in['name']) : '';
            if ($name === '' || mb_strlen($name) > 120) {
                $errors['name'] = 'Tekst van 1 tot 120 tekens.';
            } else {
                $fields['name'] = $name;
            }
        }
        if (array_key_exists('timezone', $in)) {
            $tz = $in['timezone'];
            if (!is_string($tz) || $tz === '' || strlen($tz) > 64 || !in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
                $errors['timezone'] = 'Moet een geldige IANA-tijdzone zijn.';
            } else {
                $fields['timezone'] = $tz;
            }
        }
        if ($errors === [] && $fields === []) {
            $errors['body'] = 'Geef minimaal name of timezone op.';
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        $this->app->users()->updateProfile($ctx->userId, $fields);
        return Response::json(200, ['data' => Presenter::user($this->user($ctx))]);
    }

    public function today(Request $r, AuthContext $ctx): Response
    {
        return Response::json(200, ['data' => $this->app->nextAction()->forUser($ctx->userId)]);
    }

    public function program(Request $r, AuthContext $ctx): Response
    {
        $state = $this->app->programService()->state($ctx->userId);
        if ($state === null) {
            throw ApiException::notFound('NO_ACTIVE_PROGRAM', 'Geen actief of voltooid programma gevonden.');
        }
        return Response::json(200, ['data' => $state]);
    }

    public function history(Request $r, AuthContext $ctx): Response
    {
        $page = $this->intParam($r, 'page', 1, 1, PHP_INT_MAX >> 8);
        $perPage = $this->intParam($r, 'per_page', 25, 1, 100);
        return Response::json(200, $this->app->history()->page($ctx->userId, $page, $perPage));
    }

    public function start(Request $r, AuthContext $ctx): Response
    {
        return Response::json(201, ['data' => $this->app->programService()->start($ctx->userId, $r->jsonObject())]);
    }

    public function continuation(Request $r, AuthContext $ctx): Response
    {
        $mode = $r->jsonObject()['mode'] ?? null;
        if (!is_string($mode)) {
            throw ApiException::validation(['mode' => 'Verplicht.']);
        }
        return Response::json(200, ['data' => $this->app->blockDecisions()->chooseContinuation($ctx, $mode)]);
    }

    /** @param array<string,string> $p */
    public function extend(Request $r, array $p, AuthContext $ctx): Response
    {
        return Response::json(200, ['data' => $this->app->blockDecisions()->extend($ctx, $p['block_id'])]);
    }

    /** @param array<string,string> $p */
    public function advance(Request $r, array $p, AuthContext $ctx): Response
    {
        return Response::json(200, ['data' => $this->app->blockDecisions()->advance($ctx, $p['block_id'])]);
    }

    public function complete(Request $r, AuthContext $ctx): Response
    {
        return Response::json(200, ['data' => $this->app->programService()->complete($ctx->userId)]);
    }

    /** @return array<string,mixed> */
    private function user(AuthContext $ctx): array
    {
        $u = $this->app->users()->findById($ctx->userId);
        if ($u === null) {
            throw ApiException::unauthenticated();
        }
        return $u;
    }

    private function intParam(Request $r, string $name, int $default, int $min, int $max): int
    {
        $raw = $r->query[$name] ?? null;
        if ($raw === null || $raw === '') {
            return $default;
        }
        if (preg_match('/^\d{1,9}$/', $raw) !== 1 || (int) $raw < $min || (int) $raw > $max) {
            throw ApiException::validation([$name => "Geheel getal van $min tot $max."]);
        }
        return (int) $raw;
    }
}
