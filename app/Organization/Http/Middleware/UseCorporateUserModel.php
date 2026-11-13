<?php

namespace App\Organization\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Historical tokens may resolve to Users, which now extends User and already
 * supports corporate policies. Retain normalization for other legacy callers.
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
