<?php

namespace App\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Organization\Http\Requests\StoreOrganizationDomainRequest;
use App\Organization\Http\Resources\OrganizationDomainResource;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationDomain;
use App\Organization\Services\OrganizationDomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class OrganizationDomainController extends Controller
{
    public function __construct(
        protected OrganizationDomainService $domains,
    ) {}

    public function index(Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('manageDomains', $organization);

        return OrganizationDomainResource::collection($this->domains->list($organization));
    }

    public function store(StoreOrganizationDomainRequest $request, Organization $organization): JsonResponse
    {
        Gate::authorize('manageDomains', $organization);

        return OrganizationDomainResource::make(
            $this->domains->add($organization, $request->validated(), $request->user())
        )->response()->setStatusCode(201);
    }

    public function verify(Request $request, Organization $organization, OrganizationDomain $domain): OrganizationDomainResource
    {
        Gate::authorize('manageDomains', $organization);

        return OrganizationDomainResource::make(
            $this->domains->verify($domain, $request->user())
        );
    }

    public function primary(Organization $organization, OrganizationDomain $domain): OrganizationDomainResource
    {
        Gate::authorize('manageDomains', $organization);

        return OrganizationDomainResource::make(
            $this->domains->makePrimary($domain, request()->user())
        );
    }

    public function destroy(Organization $organization, OrganizationDomain $domain): JsonResponse
    {
        Gate::authorize('manageDomains', $organization);
        $this->domains->remove($domain, request()->user());

        return response()->json(['message' => 'Organization domain removed.']);
    }
}
