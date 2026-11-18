<?php

namespace App\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Organization\Http\Requests\ListOrganizationAuditLogsRequest;
use App\Organization\Http\Resources\OrganizationAuditLogResource;
use App\Organization\Models\Organization;
use App\Organization\Repositories\Contracts\OrganizationAuditLogRepositoryInterface;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class OrganizationAuditLogController extends Controller
{
    public function __construct(
        protected OrganizationAuditLogRepositoryInterface $auditLogs
    ) {}

    public function index(ListOrganizationAuditLogsRequest $request, Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('viewAuditLogs', $organization);

        $logs = $this->auditLogs->paginateForOrganization($organization->id, $request->filters(), $request->perPage());

        return OrganizationAuditLogResource::collection($logs);
    }
}
