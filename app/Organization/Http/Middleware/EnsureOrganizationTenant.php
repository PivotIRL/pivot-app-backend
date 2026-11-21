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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tenant isolation for every /organizations/{organization}/... route.
 *
 * - Pivot staff may enter any organization (action-level policies still apply).
 * - A user with no link to the organization gets the same 404 as for an organization that does not exist,
 *   so other tenants' IDs cannot be discovered.
 * - Everyone else needs a usable administrative role in that exact organization (active or no membership),
 *   and the organization must be active or pending.
 * - A request may not point at another organization through an organization_id input.
 *
 * Mode "visibility" (organization.tenant:visibility) applies only the not-found rule. It is used by the
 * Pivot staff routes outside the tenant group, where the policy decides everything else.
 */
class EnsureOrganizationTenant
{
    public const MODE_FULL = 'full';

    public const MODE_VISIBILITY = 'visibility';

    public function __construct(
        protected OrganizationAccessService $access,
        protected AuditLogger $audit,
    ) {}

    public function handle(Request $request, Closure $next, string $mode = self::MODE_FULL): Response
    {
        $organization = $request->route('organization');
        $user = $request->user();

        if (! $organization instanceof Organization || ! $user instanceof User) {
            throw new AccessDeniedHttpException('You do not have access to this organization.');
        }

        if ($user->isPivotStaff()) {
            $this->ensureSameOrganizationInput($request, $organization);

            return $next($request);
        }

        $canEnter = $this->access->canEnterTenant($user, $organization);

        if (! $canEnter && ! $this->access->hasAnyRelationshipWith($user, $organization)) {
            $this->recordDenied($request, $organization, $user);

            throw new NotFoundHttpException('Organization not found.');
        }

        $this->ensureSameOrganizationInput($request, $organization);

        if ($mode === self::MODE_VISIBILITY) {
            return $next($request);
        }

        if (! $canEnter) {
            $this->recordDenied($request, $organization, $user);

            throw new AccessDeniedHttpException('You do not have access to this organization.');
        }

        if (! $this->access->allowsDelegatedAdministration($organization)) {
            throw new AccessDeniedHttpException($organization->status === OrganizationStatus::SUSPENDED
                ? 'This organization is suspended.'
                : "This organization's contract has expired.");
        }

        return $next($request);
    }

    // Logged without organization_id so the targeted tenant's admins cannot see other tenants' users.
    private function recordDenied(Request $request, Organization $organization, User $user): void
    {
        $this->audit->record(
            action: AuditAction::TENANT_ACCESS_DENIED,
            target: $organization,
            metadata: ['method' => $request->method(), 'path' => $request->path()],
            actor: $user,
        );
    }

    private function ensureSameOrganizationInput(Request $request, Organization $organization): void
    {
        $requested = $request->input('organization_id');

        if ($requested !== null && (string) $requested !== (string) $organization->getKey()) {
            throw new AccessDeniedHttpException('Cross-organization requests are not allowed.');
        }
    }
}
