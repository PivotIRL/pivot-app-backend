<?php

namespace App\Organization\Repositories\Contracts;

use App\Organization\Enums\OrganizationRole;
use App\Organization\Models\OrganizationRoleAssignment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface OrganizationRoleAssignmentRepositoryInterface
{
    /**
     * @return Collection<int, OrganizationRole>
     */
    public function activeRolesFor(int $userId, int $organizationId): Collection;

    /**
     * Active roles of several users in one organization, in a single query.
     *
     * @param  list<int>  $userIds
     * @return Collection<int, Collection<int, OrganizationRole>> Keyed by user ID; users with no active role are absent.
     */
    public function activeRolesForUsers(int $organizationId, array $userIds): Collection;

    public function paginateActive(int $organizationId, ?OrganizationRole $role, int $perPage): LengthAwarePaginator;

    public function findActive(int $organizationId, int $userId, OrganizationRole $role): ?OrganizationRoleAssignment;

    public function create(array $data): OrganizationRoleAssignment;

    public function revoke(OrganizationRoleAssignment $assignment): OrganizationRoleAssignment;

    /**
     * Revokes every active role of the user in the organization.
     *
     * @return Collection<int, OrganizationRole> The roles that were revoked.
     */
    public function revokeAllFor(int $organizationId, int $userId): Collection;
}
