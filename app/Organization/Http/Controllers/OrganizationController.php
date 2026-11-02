<?php

namespace App\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Organization\Http\Requests\RenewOrganizationContractRequest;
use App\Organization\Http\Requests\ListOrganizationsRequest;
use App\Organization\Http\Requests\StoreOrganizationRequest;
use App\Organization\Http\Requests\UpdateOrganizationRequest;
use App\Organization\Http\Resources\OrganizationResource;
use App\Organization\Http\Resources\OrganizationContractResource;
use App\Organization\Models\Organization;
use App\Organization\Services\OrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class OrganizationController extends Controller
{
    public function __construct(
        protected OrganizationService $organizations,
    ) {}

    public function index(ListOrganizationsRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Organization::class);

        return OrganizationResource::collection($this->organizations->paginate($request->perPage()));
    }

    public function show(Organization $organization): OrganizationResource
    {
        Gate::authorize('view', $organization);

        return OrganizationResource::make($organization);
    }

    public function contracts(Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('manageContract', $organization);

        return OrganizationContractResource::collection($this->organizations->contractsFor($organization));
    }

    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        Gate::authorize('create', Organization::class);

        $organization = $this->organizations->create($request->validated(), $request->user());

        return OrganizationResource::make($organization)
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateOrganizationRequest $request, Organization $organization): OrganizationResource
    {
        Gate::authorize('manageContract', $organization);

        return OrganizationResource::make(
            $this->organizations->update($organization, $request->validated(), $request->user())
        );
    }

    public function renew(
        RenewOrganizationContractRequest $request,
        Organization $organization,
    ): OrganizationResource {
        Gate::authorize('manageContract', $organization);

        return OrganizationResource::make(
            $this->organizations->renew($organization, $request->validated(), $request->user())
        );
    }
}
