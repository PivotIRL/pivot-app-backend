<?php

namespace App\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Organization\Http\Requests\ListRedemptionCodesRequest;
use App\Organization\Http\Requests\StoreRedemptionCodeRequest;
use App\Organization\Http\Resources\OrganizationRedemptionCodeResource;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationRedemptionCode;
use App\Organization\Services\RedemptionCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class RedemptionCodeController extends Controller
{
    public function __construct(
        protected RedemptionCodeService $codes,
    ) {}

    public function index(ListRedemptionCodesRequest $request, Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('manageCodes', $organization);

        return OrganizationRedemptionCodeResource::collection(
            $this->codes->list($organization, $request->perPage())
        );
    }

    public function store(StoreRedemptionCodeRequest $request, Organization $organization): JsonResponse
    {
        Gate::authorize('manageCodes', $organization);

        $created = $this->codes->create($organization, $request->validated(), $request->user());

        return response()->json([
            'data' => OrganizationRedemptionCodeResource::make($created['code'])->resolve($request),
            'one_time_code' => $created['plaintext'],
        ], 201);
    }

    public function destroy(
        Organization $organization,
        OrganizationRedemptionCode $redemptionCode,
    ): OrganizationRedemptionCodeResource {
        Gate::authorize('manageCodes', $organization);

        return OrganizationRedemptionCodeResource::make(
            $this->codes->deactivate($redemptionCode, request()->user())
        );
    }
}
