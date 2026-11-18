<?php

namespace App\Organization;

use App\Organization\Console\Commands\AssignPivotRoleCommand;
use App\Organization\Console\Commands\ProvisionDashboardAccountsCommand;
use App\Organization\Http\Middleware\EnsureOrganizationDashboardAccess;
use App\Organization\Http\Middleware\EnsureOrganizationTenant;
use App\Organization\Http\Middleware\UseCorporateUserModel;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationContract;
use App\Organization\Models\OrganizationDashboardAccount;
use App\Organization\Models\OrganizationDomain;
use App\Organization\Models\OrganizationIdentityProvider;
use App\Organization\Models\OrganizationInvitation;
use App\Organization\Models\OrganizationMembership;
use App\Organization\Models\OrganizationRedemptionCode;
use App\Organization\Models\OrganizationRoleAssignment;
use App\Organization\Models\OrganizationScimCredential;
use App\Organization\Policies\OrganizationMembershipPolicy;
use App\Organization\Policies\OrganizationPolicy;
use App\Organization\Policies\OrganizationRoleAssignmentPolicy;
use App\Organization\Repositories\Contracts\OrganizationAuditLogRepositoryInterface;
use App\Organization\Repositories\Contracts\OrganizationDashboardAccountRepositoryInterface;
use App\Organization\Repositories\Contracts\OrganizationInvitationRepositoryInterface;
use App\Organization\Repositories\Contracts\OrganizationMembershipRepositoryInterface;
use App\Organization\Repositories\Contracts\OrganizationRepositoryInterface;
use App\Organization\Repositories\Contracts\OrganizationRoleAssignmentRepositoryInterface;
use App\Organization\Repositories\Contracts\OrganizationDomainRepositoryInterface;
use App\Organization\Repositories\Contracts\OrganizationRedemptionCodeRepositoryInterface;
use App\Organization\Repositories\OrganizationAuditLogRepository;
use App\Organization\Repositories\OrganizationDashboardAccountRepository;
use App\Organization\Repositories\OrganizationDomainRepository;
use App\Organization\Repositories\OrganizationInvitationRepository;
use App\Organization\Repositories\OrganizationMembershipRepository;
use App\Organization\Repositories\OrganizationRedemptionCodeRepository;
use App\Organization\Repositories\OrganizationRepository;
use App\Organization\Repositories\OrganizationRoleAssignmentRepository;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class OrganizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrganizationRepositoryInterface::class, OrganizationRepository::class);
        $this->app->bind(OrganizationRoleAssignmentRepositoryInterface::class, OrganizationRoleAssignmentRepository::class);
        $this->app->bind(OrganizationMembershipRepositoryInterface::class, OrganizationMembershipRepository::class);
        $this->app->bind(OrganizationAuditLogRepositoryInterface::class, OrganizationAuditLogRepository::class);
        $this->app->bind(OrganizationDomainRepositoryInterface::class, OrganizationDomainRepository::class);
        $this->app->bind(OrganizationRedemptionCodeRepositoryInterface::class, OrganizationRedemptionCodeRepository::class);
        $this->app->bind(OrganizationInvitationRepositoryInterface::class, OrganizationInvitationRepository::class);
        $this->app->bind(OrganizationDashboardAccountRepositoryInterface::class, OrganizationDashboardAccountRepository::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        $this->registerPolicies();
        $this->registerMorphMap();
        $this->registerRateLimiters();
        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->commands([AssignPivotRoleCommand::class, ProvisionDashboardAccountsCommand::class]);
        }
    }

    private function registerPolicies(): void
    {
        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(OrganizationRoleAssignment::class, OrganizationRoleAssignmentPolicy::class);
        Gate::policy(OrganizationMembership::class, OrganizationMembershipPolicy::class);
    }

    // Stable audit target_type values instead of PHP class names.
    private function registerMorphMap(): void
    {
        Relation::morphMap([
            'organization' => Organization::class,
            'organization_contract' => OrganizationContract::class,
            'organization_domain' => OrganizationDomain::class,
            'organization_membership' => OrganizationMembership::class,
            'organization_role_assignment' => OrganizationRoleAssignment::class,
            'organization_invitation' => OrganizationInvitation::class,
            'organization_redemption_code' => OrganizationRedemptionCode::class,
            'organization_identity_provider' => OrganizationIdentityProvider::class,
            'organization_scim_credential' => OrganizationScimCredential::class,
            'organization_dashboard_account' => OrganizationDashboardAccount::class,
        ]);
    }

    // Public dashboard authentication endpoints. Counted per email and IP, and per IP alone.
    private function registerRateLimiters(): void
    {
        $emailKey = fn (Request $request) => is_string($request->input('email'))
            ? Str::lower(trim($request->input('email')))
            : '';

        RateLimiter::for('organization-dashboard-login', fn (Request $request) => [
            Limit::perMinute(5)->by($emailKey($request).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        RateLimiter::for('organization-dashboard-forgot-password', fn (Request $request) => [
            Limit::perHour(5)->by($emailKey($request).'|'.$request->ip()),
            Limit::perHour(20)->by($request->ip()),
        ]);

        RateLimiter::for('organization-dashboard-reset-password', fn (Request $request) => [
            Limit::perMinute(10)->by($request->ip()),
        ]);
    }

    private function registerRoutes(): void
    {
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('organization.user', UseCorporateUserModel::class);
        $router->aliasMiddleware('organization.tenant', EnsureOrganizationTenant::class);
        $router->aliasMiddleware('organization.dashboard', EnsureOrganizationDashboardAccess::class);

        if ($this->app->routesAreCached()) {
            return;
        }

        Route::middleware('api')
            ->prefix('api/v1')
            ->group(__DIR__.'/Routes/api.php');
    }
}
