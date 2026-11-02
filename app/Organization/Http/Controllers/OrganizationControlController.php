<?php

namespace App\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Organization\Http\Requests\OrganizationControlRequest;
use App\Organization\Http\Resources\OrganizationResource;
use App\Organization\Models\Organization;
use App\Organization\Services\OrganizationControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Pivot Super Admin control over any organization: suspend, reactivate, remove.
 */
class OrganizationControlController extends Controller
{
    public function __construct(
        protected OrganizationControlService $control
    ) {}

    public function suspend(OrganizationControlRequest $request, Organization $organization): OrganizationResource
    {
        Gate::authorize('suspend', $organization);

        return OrganizationResource::make(
            $this->control->suspend($organization, $request->user(), $request->reason())
        );
    }

    public function reactivate(OrganizationControlRequest $request, Organization $organization): OrganizationResource
    {
        Gate::authorize('reactivate', $organization);

        return OrganizationResource::make(
            $this->control->reactivate($organization, $request->user(), $request->reason())
        );
    }

    public function destroy(OrganizationControlRequest $request, Organization $organization): JsonResponse
    {
        Gate::authorize('delete', $organization);

        $this->control->remove($organization, $request->user(), $request->reason());

        return response()->json(['message' => 'Organization removed.']);
    }
}
