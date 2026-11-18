<?php

namespace App\Organization\Repositories;

use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationRedemptionCode;
use App\Organization\Repositories\Contracts\OrganizationRedemptionCodeRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class OrganizationRedemptionCodeRepository implements OrganizationRedemptionCodeRepositoryInterface
{
    public function paginateFor(Organization $organization, int $perPage): LengthAwarePaginator
    {
        return $organization->redemptionCodes()->latest('id')->paginate($perPage);
    }

    public function create(Organization $organization, array $data): OrganizationRedemptionCode
    {
        return $organization->redemptionCodes()->create($data);
    }

    public function deactivate(OrganizationRedemptionCode $code): OrganizationRedemptionCode
    {
        $code->update(['is_active' => false]);

        return $code->refresh();
    }

    public function findByHashForUpdate(string $codeHash): ?OrganizationRedemptionCode
    {
        return OrganizationRedemptionCode::query()
            ->where('code_hash', $codeHash)
            ->lockForUpdate()
            ->first();
    }

    public function recordUse(OrganizationRedemptionCode $code): OrganizationRedemptionCode
    {
        $code->forceFill([
            'uses_count' => $code->uses_count + 1,
            'last_used_at' => now(),
        ])->save();

        return $code;
    }
}
