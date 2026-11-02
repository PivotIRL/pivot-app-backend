<?php

use App\Organization\Http\Controllers\CorporateJoinController;
use App\Organization\Http\Controllers\OrganizationAuditLogController;
use App\Organization\Http\Controllers\OrganizationControlController;
use App\Organization\Http\Controllers\OrganizationController;
use App\Organization\Http\Controllers\OrganizationDomainController;
use App\Organization\Http\Controllers\OrganizationInvitationController;
use App\Organization\Http\Controllers\OrganizationMemberController;
use App\Organization\Http\Controllers\OrganizationRoleController;
use App\Organization\Http\Controllers\RedemptionCodeController;
use Illuminate\Support\Facades\Route;

/*
| Corporate organization routes. Loaded by OrganizationServiceProvider under /api/v1.
| Every route here passes tenant isolation (organization.tenant) and then a policy check in the controller.
| scopeBindings() makes child models (e.g. {roleAssignment}) resolve only inside {organization}.
*/

Route::middleware(['auth:sanctum', 'organization.user', 'organization.tenant'])
    ->prefix('organizations/{organization}')
    ->whereUuid('organization')
    ->scopeBindings()
    ->name('organizations.')
    ->group(function () {
        Route::get('/', [OrganizationController::class, 'show'])->name('show');
        Route::get('contracts', [OrganizationController::class, 'contracts'])->name('contracts.index');

        // Organization admin actions
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
        Route::delete('members/{membership}', [OrganizationMemberController::class, 'destroy'])->name('members.destroy');

        Route::get('invitations', [OrganizationInvitationController::class, 'index'])->name('invitations.index');
        Route::post('invitations', [OrganizationInvitationController::class, 'store'])->name('invitations.store');
        Route::delete('invitations/{invitation}', [OrganizationInvitationController::class, 'destroy'])->name('invitations.destroy');

        // Roles (ORG_OWNER / ORG_ADMIN / ORG_ANALYST / ORG_MEMBER)
        Route::get('roles', [OrganizationRoleController::class, 'index'])->name('roles.index');
        Route::post('roles', [OrganizationRoleController::class, 'store'])->name('roles.store');
        Route::delete('roles/{roleAssignment}', [OrganizationRoleController::class, 'destroy'])->name('roles.destroy');

        // Audit history
        Route::get('audit-logs', [OrganizationAuditLogController::class, 'index'])->name('audit-logs.index');

        // Pivot Super Admin control
        Route::post('suspend', [OrganizationControlController::class, 'suspend'])->name('suspend');
        Route::post('reactivate', [OrganizationControlController::class, 'reactivate'])->name('reactivate');
        Route::delete('/', [OrganizationControlController::class, 'destroy'])->name('destroy');
    });

Route::middleware(['auth:sanctum', 'organization.user'])
    ->prefix('organizations')
    ->name('organizations.')
    ->group(function () {
        // Pivot staff actions; action-level policies enforce the global role permissions.
        Route::get('/', [OrganizationController::class, 'index'])->name('index');
        Route::post('/', [OrganizationController::class, 'store'])->name('store');
        Route::put('{organization}', [OrganizationController::class, 'update'])->whereUuid('organization')->name('update');
        Route::post('{organization}/contracts/renew', [OrganizationController::class, 'renew'])->whereUuid('organization')->name('contracts.renew');
    });

// Employee onboarding (B4): any signed-in user joins with their own existing account, never a new one.
Route::middleware(['auth:sanctum', 'organization.user'])
    ->prefix('corporate')
    ->name('corporate.')
    ->group(function () {
        Route::post('join/work-email', [CorporateJoinController::class, 'workEmail'])->name('join.work-email');
        Route::post('join/code', [CorporateJoinController::class, 'redeemCode'])->name('join.code');
        Route::post('invitations/accept', [CorporateJoinController::class, 'acceptInvitation'])->name('invitations.accept');
    });
