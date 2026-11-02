<?php

namespace App\Organization\Repositories;

use App\Organization\Enums\MembershipStatus;
use App\Organization\Enums\OrganizationStatus;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationContract;
use App\Organization\Repositories\Contracts\OrganizationRepositoryInterface;
use Illuminate\Support\Carbon;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class OrganizationRepository implements OrganizationRepositoryInterface
{
    public function create(array $data): Organization
    {
        return Organization::create($data);
    }

    public function update(Organization $organization, array $data): Organization
    {
        $organization->update($data);

        return $organization->refresh();
    }

    public function paginate(int $perPage): LengthAwarePaginator
    {
        return Organization::query()
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function contractsFor(Organization $organization): Collection
    {
        return OrganizationContract::query()
            ->where('organization_id', $organization->id)
            ->orderByDesc('id')
            ->get();
    }

    public function updateStatus(Organization $organization, OrganizationStatus $status): Organization
    {
        $organization->update(['status' => $status]);

        return $organization;
    }

    public function eachExpiredBefore(Carbon $date, Closure $callback): void
    {
        Organization::query()
            ->whereIn('status', [OrganizationStatus::PENDING, OrganizationStatus::ACTIVE, OrganizationStatus::SUSPENDED])
            ->whereNotNull('contract_end_at')
            ->where('contract_end_at', '<=', $date)
            ->orderBy('id')
            ->chunkById(100, function ($organizations) use ($callback): void {
                foreach ($organizations as $organization) {
                    $callback($organization);
                }
            });
    }

    public function eachPendingStartedBy(Carbon $date, Closure $callback): void
    {
        Organization::query()
            ->where('status', OrganizationStatus::PENDING)
            ->whereNotNull('contract_start_at')
            ->where('contract_start_at', '<=', $date)
            ->where('contract_end_at', '>', $date)
            ->orderBy('id')
            ->chunkById(100, function ($organizations) use ($callback): void {
                foreach ($organizations as $organization) {
                    $callback($organization);
                }
            });
    }

    public function lockForSeatAllocation(Organization $organization): Organization
    {
        return Organization::withTrashed()
            ->whereKey($organization->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function eachWithActiveMembershipCount(Closure $callback): void
    {
        Organization::withTrashed()
            ->withCount(['memberships as active_memberships_count' => fn ($query) => $query->where('status', MembershipStatus::ACTIVE)])
            ->orderBy('id')
            ->chunkById(100, function ($organizations) use ($callback): void {
                foreach ($organizations as $organization) {
                    $callback($organization);
                }
            });
    }

    // Soft delete: the record and its audit history are kept.
    public function delete(Organization $organization): void
    {
        $organization->delete();
    }
}
