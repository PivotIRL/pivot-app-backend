<?php

namespace App\Organization\Services;

use App\Models\User;
use App\Organization\Enums\AuditAction;
use App\Organization\Enums\OrganizationPermission;
use App\Organization\Enums\OrganizationRole;
use App\Organization\Exceptions\OrganizationActionException;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationRoleAssignment;
use App\Organization\Repositories\Contracts\OrganizationMembershipRepositoryInterface;
use App\Organization\Repositories\Contracts\OrganizationRoleAssignmentRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Assigns and revokes ORG_* roles. Authorization (who may assign which role) is checked
 * by OrganizationRoleAssignmentPolicy before these methods are called.
 */
class RoleAssignmentService
{
    public function __construct(
        protected OrganizationRoleAssignmentRepositoryInterface $roleAssignments,
        protected OrganizationMembershipRepositoryInterface $memberships,
        protected OrganizationAccessService $access,
        protected AuditLogger $audit,
    ) {}

    public function list(Organization $organization, ?OrganizationRole $role = null, int $perPage = 25): LengthAwarePaginator
    {
        return $this->roleAssignments->paginateActive($organization->id, $role, $perPage);
    }

    public function assign(Organization $organization, User $user, OrganizationRole $role, User $actor): OrganizationRoleAssignment
    {
        // Corporate admins can only give roles to their own members. Pivot staff with override
        // rights can assign anyone (for example, the first ORG_OWNER of a new organization).
        $canOverride = $this->access->hasPermission($actor, OrganizationPermission::ORGANIZATION_OVERRIDE, $organization);

        if (! $canOverride && ! $this->memberships->isActiveMember($organization->id, $user->id)) {
            throw OrganizationActionException::notAnActiveMember();
        }

        if ($this->roleAssignments->findActive($organization->id, $user->id, $role)) {
            throw OrganizationActionException::roleAlreadyAssigned();
        }

        return DB::transaction(function () use ($organization, $user, $role, $actor) {
            try {
                $assignment = $this->roleAssignments->create([
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                    'role' => $role,
                    'assigned_by' => $actor->id,
                    'assigned_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw OrganizationActionException::roleAlreadyAssigned();
            }

            $this->audit->record(
                action: AuditAction::ORGANIZATION_ROLE_ASSIGNED,
                organization: $organization,
                target: $assignment,
                metadata: ['user_id' => $user->id, 'role' => $role->value],
                actor: $actor,
            );

            return $assignment->load('user:id,name,email');
        });
    }

    public function revoke(OrganizationRoleAssignment $assignment, User $actor, ?string $reason = null): OrganizationRoleAssignment
    {
        if (! $assignment->isActive()) {
            throw OrganizationActionException::roleAlreadyRevoked();
        }

        return DB::transaction(function () use ($assignment, $actor, $reason) {
            $this->roleAssignments->revoke($assignment);

            $this->audit->record(
                action: AuditAction::ORGANIZATION_ROLE_REVOKED,
                organization: $assignment->organization_id,
                target: $assignment,
                metadata: array_filter([
                    'user_id' => $assignment->user_id,
                    'role' => $assignment->role->value,
                    'reason' => $reason,
                ]),
                actor: $actor,
            );

            return $assignment->load('user:id,name,email');
        });
    }
}
