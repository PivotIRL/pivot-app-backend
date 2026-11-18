<?php

namespace App\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Organization\Http\Requests\ForgotOrganizationDashboardPasswordRequest;
use App\Organization\Http\Requests\OrganizationDashboardLoginRequest;
use App\Organization\Http\Requests\ResetOrganizationDashboardPasswordRequest;
use App\Organization\Http\Resources\OrganizationDashboardLoginResource;
use App\Organization\Services\OrganizationDashboardAuthService;
use App\Organization\Services\OrganizationDashboardPasswordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Organization Dashboard authentication. Separate from the mobile /auth endpoints.
 */
class OrganizationDashboardAuthController extends Controller
{
    public function __construct(
        protected OrganizationDashboardAuthService $auth,
        protected OrganizationDashboardPasswordService $passwords,
    ) {}

    public function login(OrganizationDashboardLoginRequest $request): OrganizationDashboardLoginResource
    {
        return OrganizationDashboardLoginResource::make(
            $this->auth->login($request->validated('email'), $request->validated('password'))
        );
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request->user());

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function forgotPassword(ForgotOrganizationDashboardPasswordRequest $request): JsonResponse
    {
        $this->passwords->requestLink($request->validated('email'));

        return response()->json([
            'message' => 'If a dashboard account exists for this email, a password link has been sent.',
        ]);
    }

    public function resetPassword(ResetOrganizationDashboardPasswordRequest $request): JsonResponse
    {
        $this->passwords->resetPassword(
            $request->validated('email'),
            $request->validated('token'),
            $request->validated('password'),
        );

        return response()->json(['message' => 'Your password has been set. Please sign in.']);
    }
}
