<?php

namespace App\Organization\Http\Middleware;

use App\Models\User;
use App\Organization\Enums\AuditAction;
use App\Organization\Enums\OrganizationStatus;
use App\Organization\Models\Organization;
use App\Organization\Services\AuditLogger;
use App\Organization\Services\OrganizationAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tenant isolation for every /organizations/{organization}/... route.
 *
 * - Pivot staff may enter any organization (action-level policies still apply).
 * - Everyone else needs an active administrative role in that exact organization.
 * - A request may not point at another organization through an organization_id input.
 */
class EnsureOrganizationTenant
{
    public function __construct(
        protected OrganizationAccessService $access,
        protected AuditLogger $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $organization = $request->route('organization');
        $user = $request->user();

        if (! $organization instanceof Organization || ! $user instanceof User) {
            throw new AccessDeniedHttpException('You do not have access to this organization.');
        }

        $this->ensureSameOrganizationInput($request, $organization);

        if ($user->isPivotStaff()) {
            return $next($request);
        }

        if (! $this->access->canEnterTenant($user, $organization)) {
            // Logged without organization_id so the targeted tenant's admins cannot see other tenants' users.
            $this->audit->record(
                action: AuditAction::TENANT_ACCESS_DENIED,
                target: $organization,
                metadata: ['method' => $request->method(), 'path' => $request->path()],
                actor: $user,
            );

            throw new AccessDeniedHttpException('You do not have access to this organization.');
        }

        if ($organization->status === OrganizationStatus::SUSPENDED) {
            throw new AccessDeniedHttpException('This organization is suspended.');
        }

        return $next($request);
    }

    private function ensureSameOrganizationInput(Request $request, Organization $organization): void
    {
        $requested = $request->input('organization_id');

        if ($requested !== null && (string) $requested !== $organization->getKey()) {
            throw new AccessDeniedHttpException('Cross-organization requests are not allowed.');
        }
    }
}
