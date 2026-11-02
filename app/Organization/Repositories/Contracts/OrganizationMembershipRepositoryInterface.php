<?php

namespace App\Organization\Repositories\Contracts;

use App\Organization\Enums\MembershipStatus;
use App\Organization\Models\OrganizationMembership;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

interface OrganizationMembershipRepositoryInterface
{
    public function isActiveMember(string $organizationId, int $userId): bool;

    public function countActive(string $organizationId): int;

    public function paginateFor(string $organizationId, ?MembershipStatus $status, int $perPage): LengthAwarePaginator;

    /**
     * The user's membership row in this organization. Rows are reused, so there is normally one per user.
     */
    public function findFor(string $organizationId, int $userId): ?OrganizationMembership;

    /**
     * The user's active membership in any organization (at most one, enforced by a unique index).
     */
    public function findActiveForUser(int $userId): ?OrganizationMembership;

    public function create(array $data): OrganizationMembership;

    public function update(OrganizationMembership $membership, array $data): OrganizationMembership;

    public function isSuspendedMember(string $organizationId, int $userId): bool;

    /**
     * Expire every active membership of the organization. Returns the number expired.
     */
    public function expireActive(string $organizationId, string $reason, Carbon $at): int;

    /**
     * Deactivate every active, suspended or invited membership of the organization. Returns the number changed.
     */
    public function deactivateAll(string $organizationId, string $reason, Carbon $at): int;

    /**
     * Memberships expired by a contract expiry that may be restored: the user is not active
     * in another organization and the membership's own expires_at has not passed.
     * Oldest members first.
     *
     * @return Collection<int, OrganizationMembership>
     */
    public function restorableAfterContractExpiry(string $organizationId, Carbon $at): Collection;

    /**
     * @param  list<int>  $membershipIds
     */
    public function restore(array $membershipIds, Carbon $at): int;
}
