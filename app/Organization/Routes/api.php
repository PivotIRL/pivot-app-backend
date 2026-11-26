<?php

use App\Organization\Http\Controllers\CorporateAccessController;
use App\Organization\Http\Controllers\CorporateJoinController;
use App\Organization\Http\Controllers\OrganizationAuditLogController;
use App\Organization\Http\Controllers\OrganizationControlController;
use App\Organization\Http\Controllers\OrganizationController;
use App\Organization\Http\Controllers\OrganizationDashboardAuthController;
use App\Organization\Http\Controllers\OrganizationDomainController;
use App\Organization\Http\Controllers\OrganizationInvitationController;
use App\Organization\Http\Controllers\OrganizationMemberController;
use App\Organization\Http\Controllers\OrganizationRoleController;
use App\Organization\Http\Controllers\PermissionCatalogController;
use App\Organization\Http\Controllers\RedemptionCodeController;
use App\Organization\Http\Controllers\RoleCatalogController;
use Illuminate\Support\Facades\Route;

/*
| Corporate organization routes. Loaded by OrganizationServiceProvider under /api/v1.
|
| Three separate audiences:
|   1. Organization Dashboard: dashboard token only (organization admins and Pivot staff).
|   2. Pivot staff administration: dashboard token only; policies allow only permitted staff.
|   3. Mobile App joining: mobile token only.
|
| Every {organization} route passes tenant isolation (organization.tenant) and then a policy check in the controller.
| scopeBindings() makes child models (e.g. {roleAssignment}) resolve only inside {organization}.
|
| organization.auth is the shared Authenticate middleware, ordered before route model binding so a missing or
| invalid token returns 401 before any ID is looked up. IDs are limited to bigint-sized numbers, so malformed
| IDs never reach the database.
*/

$id = '[0-9]{1,19}';

// Dashboard authentication: public endpoints, rate limited.
Route::prefix('organization/auth')
    ->name('organization.auth.')
    ->controller(OrganizationDashboardAuthController::class)
    ->group(function () {
        Route::post('login', 'login')->middleware('throttle:organization-dashboard-login')->name('login');
        Route::post('forgot-password', 'forgotPassword')->middleware('throttle:organization-dashboard-forgot-password')->name('forgot-password');
        Route::post('reset-password', 'resetPassword')->middleware('throttle:organization-dashboard-reset-password')->name('reset-password');
    });

Route::middleware(['organization.auth:organization-dashboard', 'organization.dashboard'])->group(function () use ($id) {
    Route::post('organization/auth/logout', [OrganizationDashboardAuthController::class, 'logout'])->name('organization.auth.logout');

    // The signed-in dashboard user's own organizations, roles and permissions.
    Route::get('organization/me', [CorporateAccessController::class, 'me'])->name('organization.me');

    // Role and permission definitions (what exists, not what the user holds).
    Route::get('organization/roles', [RoleCatalogController::class, 'index'])->name('organization.roles.index');
    Route::get('organization/permissions', [PermissionCatalogController::class, 'index'])->name('organization.permissions.index');

    // Role and permission definitions (what exists, not what the user holds).
    Route::get('corporate/roles', [RoleCatalogController::class, 'index'])->name('corporate.roles.index');
    Route::get('corporate/permissions', [PermissionCatalogController::class, 'index'])->name('corporate.permissions.index');

    Route::middleware('organization.tenant')
        ->prefix('organizations/{organization}')
        ->where([
            'organization' => $id,
            'domain' => $id,
            'redemptionCode' => $id,
            'membership' => $id,
            'invitation' => $id,
            'roleAssignment' => $id,
        ])
        ->scopeBindings()
        ->name('organizations.')
        ->group(function () {
            // 1. Organization Dashboard.
            Route::get('/', [OrganizationController::class, 'show'])->name('show');
            Route::get('contracts', [OrganizationController::class, 'contracts'])->name('contracts.index');

            Route::get('domains', [OrganizationDomainController::class, 'index'])->name('domains.index');
            Route::post('domains', [OrganizationDomainController::class, 'store'])->name('domains.store');
            Route::post('domains/{domain}/verify', [OrganizationDomainController::class, 'verify'])->name('domains.verify');
            Route::post('domains/{domain}/primary', [OrganizationDomainController::class, 'primary'])->name('domains.primary');
            Route::delete('domains/{domain}', [OrganizationDomainController::class, 'destroy'])->name('domains.destroy');

            Route::get('redemption-codes', [RedemptionCodeController::class, 'index'])->name('redemption-codes.index');
            Route::post('redemption-codes', [RedemptionCodeController::class, 'store'])->name('redemption-codes.store');
            Route::delete('redemption-codes/{redemptionCode}', [RedemptionCodeController::class, 'destroy'])->name('redemption-codes.destroy');

            // Members and seats (B4)
            Route::get('members', [OrganizationMemberController::class, 'index'])->name('members.index');
            Route::post('members/{membership}/suspend', [OrganizationMemberController::class, 'suspend'])->name('members.suspend');
            Route::post('members/{membership}/restore', [OrganizationMemberController::class, 'restore'])->name('members.restore');
            Route::match(['put', 'patch'], 'members/{membership}', [OrganizationMemberController::class, 'update'])->name('members.update');
            Route::delete('members/{membership}', [OrganizationMemberController::class, 'destroy'])->name('members.destroy');
            // Permanent delete in any status; the person may join again later as a new member.
            Route::delete('members/{membership}/remove', [OrganizationMemberController::class, 'remove'])->name('members.remove');

            Route::get('invitations', [OrganizationInvitationController::class, 'index'])->name('invitations.index');
            Route::post('invitations', [OrganizationInvitationController::class, 'store'])->name('invitations.store');
            Route::delete('invitations/{invitation}', [OrganizationInvitationController::class, 'destroy'])->name('invitations.destroy');

            // Roles (ORG_OWNER / ORG_ADMIN / ORG_ANALYST / ORG_MEMBER)
            Route::get('roles', [OrganizationRoleController::class, 'index'])->name('roles.index');
            Route::post('roles', [OrganizationRoleController::class, 'store'])->name('roles.store');
            Route::delete('roles/{roleAssignment}', [OrganizationRoleController::class, 'destroy'])->name('roles.destroy');

            // Audit history
            Route::get('audit-logs', [OrganizationAuditLogController::class, 'index'])->name('audit-logs.index');

            // 2. Pivot staff administration of one organization.
            Route::post('suspend', [OrganizationControlController::class, 'suspend'])->name('suspend');
            Route::post('reactivate', [OrganizationControlController::class, 'reactivate'])->name('reactivate');
            Route::delete('/', [OrganizationControlController::class, 'destroy'])->name('destroy');
        });

    // 2. Pivot staff administration across organizations; action-level policies enforce the global role permissions.
    Route::prefix('organizations')
        ->name('organizations.')
        ->group(function () use ($id) {
            Route::get('/', [OrganizationController::class, 'index'])->name('index');
            Route::post('/', [OrganizationController::class, 'store'])->name('store');
            // Visibility only: users with no link to the organization get 404, the policy decides the rest.
            Route::put('{organization}', [OrganizationController::class, 'update'])
                ->where('organization', $id)
                ->middleware('organization.tenant:visibility')
                ->name('update');
            Route::post('{organization}/contracts/renew', [OrganizationController::class, 'renew'])
                ->where('organization', $id)
                ->middleware('organization.tenant:visibility')
                ->name('contracts.renew');
        });
});

// 3. Mobile App employee onboarding (B4): any signed-in user joins with their own existing account, never a new one.
Route::middleware(['organization.auth:sanctum', 'organization.user'])
    ->prefix('organization')
    ->name('organization.')
    ->group(function () {
        Route::post('join/work-email', [CorporateJoinController::class, 'workEmail'])->name('join.work-email');
        Route::post('join/code', [CorporateJoinController::class, 'redeemCode'])->name('join.code');
        Route::post('invitations/accept', [CorporateJoinController::class, 'acceptInvitation'])
            ->middleware('throttle:organization-invitation-accept')
            ->name('invitations.accept');
    });

// Public: the invited person may not have an account yet.
Route::get('organization/invitations/preview', [OrganizationInvitationController::class, 'preview'])
    ->middleware('throttle:organization-invitation-preview')
    ->name('organization.invitations.preview');
