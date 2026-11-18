<?php

namespace App\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Organization\Data\CorporateJoinResult;
use App\Organization\Http\Requests\AcceptOrganizationInvitationRequest;
use App\Organization\Http\Requests\RedeemOrganizationCodeRequest;
use App\Organization\Http\Resources\CorporateJoinResource;
use App\Organization\Services\CorporateOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Employee-side join endpoints. They always link the signed-in Pivot account (no second account is created).
 * Failures are returned as { message, join_status } by CorporateJoinException.
 */
class CorporateJoinController extends Controller
{
    public function __construct(
        protected CorporateOnboardingService $onboarding,
    ) {}

    public function workEmail(Request $request): JsonResponse
    {
        return $this->respond($request, $this->onboarding->joinByWorkEmail($request->user()));
    }

    public function redeemCode(RedeemOrganizationCodeRequest $request): JsonResponse
    {
        return $this->respond($request, $this->onboarding->redeemCode($request->user(), $request->code()));
    }

    public function acceptInvitation(AcceptOrganizationInvitationRequest $request): JsonResponse
    {
        return $this->respond($request, $this->onboarding->acceptInvitation($request->user(), $request->token()));
    }

    // 201 when a seat was taken now, 200 when the user was already a member.
    private function respond(Request $request, CorporateJoinResult $result): JsonResponse
    {
        $result->membership?->loadMissing('organization');

        return CorporateJoinResource::make($result)
            ->response($request)
            ->setStatusCode($result->status->httpStatus());
    }
}
