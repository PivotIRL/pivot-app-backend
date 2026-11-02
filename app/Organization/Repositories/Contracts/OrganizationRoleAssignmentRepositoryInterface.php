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
    public function activeRolesFor(int $userId, string $organizationId): Collection;

    public function paginateActive(string $organizationId, ?OrganizationRole $role, int $perPage): LengthAwarePaginator;

    public function findActive(string $organizationId, int $userId, OrganizationRole $role): ?OrganizationRoleAssignment;

    public function create(array $data): OrganizationRoleAssignment;

    public function revoke(OrganizationRoleAssignment $assignment): OrganizationRoleAssignment;

    /**
     * Revokes every active role of the user in the organization.
     *
     * @return Collection<int, OrganizationRole> The roles that were revoked.
     */
    public function revokeAllFor(string $organizationId, int $userId): Collection;
}
