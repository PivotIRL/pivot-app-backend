<?php

namespace App\Organization\Repositories\Contracts;

use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationRedemptionCode;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface OrganizationRedemptionCodeRepositoryInterface
{
    public function paginateFor(Organization $organization, int $perPage): LengthAwarePaginator;

    public function create(Organization $organization, array $data): OrganizationRedemptionCode;

    public function deactivate(OrganizationRedemptionCode $code): OrganizationRedemptionCode;

    /**
     * Finds a code by hash and locks its row until the transaction ends, so use limits cannot be passed.
     */
    public function findByHashForUpdate(string $codeHash): ?OrganizationRedemptionCode;

    public function recordUse(OrganizationRedemptionCode $code): OrganizationRedemptionCode;
}
