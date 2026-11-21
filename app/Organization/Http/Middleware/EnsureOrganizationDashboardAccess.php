<?php

namespace App\Organization\Http\Middleware;

use App\Organization\Exceptions\OrganizationDashboardAuthException;
use App\Organization\Models\OrganizationDashboardAccount;
use App\Organization\Services\OrganizationAccessService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after auth:organization-dashboard on every dashboard route.
 *
 * Rechecks the token, the account and the user's current access on each request, so losing
 * access takes effect immediately rather than when the token expires. It then makes the real
 * App\Models\User the authenticated user, so existing policies and services keep working.
 *
 * AuthenticatesRequests (a marker interface) keeps it ahead of SubstituteBindings, so a disabled account
 * or a user without dashboard access gets its 403 before any route model is looked up.
 */
class EnsureOrganizationDashboardAccess implements AuthenticatesRequests
{
    public function __construct(
        protected OrganizationAccessService $access,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->user();

        if (! $account instanceof OrganizationDashboardAccount
            || ! $account->tokenCan(OrganizationDashboardAccount::TOKEN_ABILITY)) {
            throw new AuthenticationException('Unauthenticated.', [OrganizationDashboardAccount::GUARD]);
        }

        if ($account->isDisabled()) {
            throw OrganizationDashboardAuthException::accountDisabled();
        }

        $user = $account->user;

        if (! $this->access->hasDashboardAccess($user)) {
            throw OrganizationDashboardAuthException::noDashboardAccess();
        }

        $user->withAccessToken($account->currentAccessToken());
        $user->setRelation('organizationDashboardAccount', $account);
        auth()->setUser($user);

        $response = $next($request);

        // The guard instance can outlive this request (in tests, for example).
        auth()->setUser($account);

        return $response;
    }
}
