<?php

namespace App\Organization\Repositories;

use App\Organization\Models\OrganizationAuditLog;
use App\Organization\Repositories\Contracts\OrganizationAuditLogRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class OrganizationAuditLogRepository implements OrganizationAuditLogRepositoryInterface
{
    public function create(array $data): OrganizationAuditLog
    {
        return OrganizationAuditLog::create($data);
    }

    public function paginateForOrganization(string $organizationId, array $filters, int $perPage): LengthAwarePaginator
    {
        return OrganizationAuditLog::query()
            ->with('actor:id,name,email')
            ->where('organization_id', $organizationId)
            ->when($filters['action'] ?? null, fn ($query, $action) => $query->where('action', $action))
            ->when($filters['actor_user_id'] ?? null, fn ($query, $actorId) => $query->where('actor_user_id', $actorId))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('created_at', '<=', Carbon::parse($to)->endOfDay()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
