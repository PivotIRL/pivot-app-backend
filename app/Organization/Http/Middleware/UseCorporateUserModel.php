<?php

namespace App\Organization\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The existing /auth/login issues Sanctum tokens on App\Models\Users, so $request->user()
 * can be a Users instance. Corporate policies and services work with App\Models\User,
 * so swap in the matching User (same row) for the rest of the request.
 */
class UseCorporateUserModel
{
    public function handle(Request $request, Closure $next): Response
    {
        $authenticated = $request->user();

        if ($authenticated !== null && ! $authenticated instanceof User) {
            $user = User::findOrFail($authenticated->getKey());

            if (method_exists($authenticated, 'currentAccessToken') && $authenticated->currentAccessToken()) {
                $user->withAccessToken($authenticated->currentAccessToken());
            }

            auth()->setUser($user);
        }

        return $next($request);
    }
}
