<?php

namespace App\Organization\Repositories\Contracts;

use App\Organization\Models\OrganizationAuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface OrganizationAuditLogRepositoryInterface
{
    public function create(array $data): OrganizationAuditLog;

    /**
     * @param  array{action?: string, actor_user_id?: int, from?: string, to?: string}  $filters
     */
    public function paginateForOrganization(string $organizationId, array $filters, int $perPage): LengthAwarePaginator;
}
