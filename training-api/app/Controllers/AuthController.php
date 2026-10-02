<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Application;
use App\Auth\AuthContext;
use App\Http\Request;
use App\Http\Response;

final class AuthController
{
    public function __construct(private Application $app)
    {
    }

    public function login(Request $r): Response
    {
        $result = $this->app->auth()->login($r->jsonObject(), $r->ip);
        return Response::json(200, ['data' => ['user' => $result['user'], 'csrf_token' => $result['csrf']]])
            ->addCookie($result['cookie']);
    }

    public function csrf(Request $r, AuthContext $ctx): Response
    {
        return Response::json(200, ['data' => ['csrf_token' => $this->app->sessions()->rotateCsrf($ctx)]]);
    }

    public function logout(Request $r, AuthContext $ctx): Response
    {
        $this->app->sessions()->destroy($ctx);
        return Response::noContent()->addCookie($this->app->sessions()->expiredCookieHeader());
    }
}
