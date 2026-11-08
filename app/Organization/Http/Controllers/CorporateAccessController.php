<?php

namespace App\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Organization\Http\Resources\CorporateAccessSummaryResource;
use App\Organization\Services\CorporateAccessService;
use Illuminate\Http\Request;

/**
 * Tells the Organization Dashboard who is signed in, which organizations they can access and what they can do there.
 * It only ever describes the user's own access, so it needs no policy check.
 */
class CorporateAccessController extends Controller
{
    public function __construct(
        protected CorporateAccessService $access,
    ) {}

    public function me(Request $request): CorporateAccessSummaryResource
    {
        $user = $request->user();

        return CorporateAccessSummaryResource::make(
            $this->access->summaryFor($user, $user->organizationDashboardAccount)
        );
    }
}
