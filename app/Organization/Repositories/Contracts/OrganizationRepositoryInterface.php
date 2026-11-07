<?php

namespace App\Organization\Repositories\Contracts;

use App\Organization\Enums\OrganizationStatus;
use App\Organization\Models\Organization;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Closure;

interface OrganizationRepositoryInterface
{
    public function create(array $data): Organization;

    public function update(Organization $organization, array $data): Organization;

    public function paginate(int $perPage): LengthAwarePaginator;

    /**
     * Organizations where the user holds an active role, or an active, suspended or expired membership.
     * Each one has `memberships` loaded with only this user's rows, newest first.
     *
     * @return Collection<int, Organization>
     */
    public function connectedToUser(int $userId): Collection;

    /**
     * Organizations where the user holds an active ORG_OWNER, ORG_ADMIN or ORG_ANALYST role.
     *
     * @return Collection<int, Organization>
     */
    public function withAdministrativeRoleHeldBy(int $userId): Collection;

    /** @return Collection<int, \App\Organization\Models\OrganizationContract> */
    public function contractsFor(Organization $organization): Collection;

    public function updateStatus(Organization $organization, OrganizationStatus $status): Organization;

    public function eachExpiredBefore(Carbon $date, Closure $callback): void;

    public function eachPendingStartedBy(Carbon $date, Closure $callback): void;

    public function delete(Organization $organization): void;

    /**
     * Reloads the organization and locks its row until the transaction ends (SELECT … FOR UPDATE).
     */
    public function lockForSeatAllocation(Organization $organization): Organization;

    /**
     * Every organization, including removed ones, with its active membership count as active_memberships_count.
     */
    public function eachWithActiveMembershipCount(Closure $callback): void;
}
