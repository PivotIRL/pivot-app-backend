<?php

namespace App\Organization\Repositories;

use App\Organization\Enums\OrganizationRole;
use App\Organization\Models\OrganizationRoleAssignment;
use App\Organization\Repositories\Contracts\OrganizationRoleAssignmentRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class OrganizationRoleAssignmentRepository implements OrganizationRoleAssignmentRepositoryInterface
{
    public function activeRolesFor(int $userId, string $organizationId): Collection
    {
        return OrganizationRoleAssignment::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->get()
            ->pluck('role');
    }

    public function paginateActive(string $organizationId, ?OrganizationRole $role, int $perPage): LengthAwarePaginator
    {
        return OrganizationRoleAssignment::query()
            ->with('user:id,name,email')
            ->where('organization_id', $organizationId)
            ->whereNull('revoked_at')
            ->when($role, fn ($query) => $query->where('role', $role))
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function findActive(string $organizationId, int $userId, OrganizationRole $role): ?OrganizationRoleAssignment
    {
        return OrganizationRoleAssignment::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('role', $role)
            ->whereNull('revoked_at')
            ->first();
    }

    public function create(array $data): OrganizationRoleAssignment
    {
        return OrganizationRoleAssignment::create($data);
    }

    public function revoke(OrganizationRoleAssignment $assignment): OrganizationRoleAssignment
    {
        $assignment->update(['revoked_at' => now()]);

        return $assignment;
    }

    public function revokeAllFor(string $organizationId, int $userId): Collection
    {
        $active = OrganizationRoleAssignment::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->whereNull('revoked_at');

        $roles = (clone $active)->get()->pluck('role');
        $active->update(['revoked_at' => now()]);

        return $roles;
    }
}
