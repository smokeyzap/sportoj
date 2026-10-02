<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Application;
use App\Auth\AuthContext;
use App\Http\Request;
use App\Http\Response;

final class WorkoutController
{
    public function __construct(private Application $app)
    {
    }

    /** @param array<string,string> $p */
    public function show(Request $r, array $p, AuthContext $ctx): Response
    {
        return Response::json(200, ['data' => $this->app->extraWorkouts()->workout($p['workout_id'])]);
    }

    /** @param array<string,string> $p */
    public function startSession(Request $r, array $p, AuthContext $ctx): Response
    {
        return Response::json(201, ['data' => $this->app->extraWorkouts()->start($ctx, $p['workout_id'])]);
    }

    /** @param array<string,string> $p */
    public function completeSession(Request $r, array $p, AuthContext $ctx): Response
    {
        return Response::json(200, ['data' => $this->app->extraWorkouts()->complete($ctx, $p['session_id'], AssignmentController::notes($r))]);
    }
}
