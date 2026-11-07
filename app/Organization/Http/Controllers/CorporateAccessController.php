<?php

namespace App\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Organization\Http\Resources\CorporateAccessSummaryResource;
use App\Organization\Services\CorporateAccessService;
use Illuminate\Http\Request;

/**
 * Tells a signed-in client which organizations the user belongs to and what they can do there.
 * It only ever describes the user's own access, so it needs no policy check.
 */
class CorporateAccessController extends Controller
{
    public function __construct(
        protected CorporateAccessService $access,
    ) {}

    public function me(Request $request): CorporateAccessSummaryResource
    {
        return CorporateAccessSummaryResource::make($this->access->summaryFor($request->user()));
    }
}
