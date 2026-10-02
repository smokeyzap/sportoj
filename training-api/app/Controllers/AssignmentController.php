<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Application;
use App\Auth\AuthContext;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;

final class AssignmentController
{
    public function __construct(private Application $app)
    {
    }

    /** @param array<string,string> $p */
    public function show(Request $r, array $p, AuthContext $ctx): Response
    {
        return Response::json(200, ['data' => $this->app->assignments()->get($ctx, $p['assignment_id'])]);
    }

    /** @param array<string,string> $p */
    public function start(Request $r, array $p, AuthContext $ctx): Response
    {
        return Response::json(200, ['data' => $this->app->assignments()->start($ctx, $p['assignment_id'])]);
    }

    /** @param array<string,string> $p */
    public function complete(Request $r, array $p, AuthContext $ctx): Response
    {
        return Response::json(200, ['data' => $this->app->assignments()->complete($ctx, $p['assignment_id'], self::notes($r))]);
    }

    /** @param array<string,string> $p */
    public function skip(Request $r, array $p, AuthContext $ctx): Response
    {
        return Response::json(200, ['data' => $this->app->assignments()->skip($ctx, $p['assignment_id'])]);
    }

    /** @param array<string,string> $p */
    public function reopen(Request $r, array $p, AuthContext $ctx): Response
    {
        return Response::json(200, ['data' => $this->app->assignments()->reopen($ctx, $p['assignment_id'])]);
    }

    /** NotesRequest: optional string|null, max 5000 characters. */
    public static function notes(Request $r): ?string
    {
        $in = $r->jsonObject();
        $notes = $in['notes'] ?? null;
        if ($notes !== null && (!is_string($notes) || mb_strlen($notes) > 5000)) {
            throw ApiException::validation(['notes' => 'Tekst van maximaal 5000 tekens.']);
        }
        return $notes;
    }
}
