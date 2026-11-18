<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\V1\MembershipController;
use App\Models\User;
use App\Models\Users;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Models\UserProfile;
use App\Services\Membership\MembershipAccessService;
use App\Services\Membership\MembershipProductCatalog;
use App\Services\Membership\MembershipReconciliationService;
use App\Services\RevenueCatService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class MembershipAuthenticatedUserTest extends TestCase
{
    #[DataProvider('userModels')]
    public function test_trial_checkout_handles_the_login_model_without_a_type_error(string $model): void
    {
        $user = (new $model())->forceFill(['id' => 42]);
        $user->setRelation('profile', (new UserProfile())->forceFill(['onboarding_completed' => true]));
        $attempts = Mockery::mock(Builder::class);
        $attempts->shouldReceive('where')->andReturnSelf();
        $attempts->shouldReceive('first')->once()->andReturnNull();
        DB::shouldReceive('table')->with('membership_attempts')->once()->andReturn($attempts);
        $trials = Mockery::mock(Builder::class);
        $trials->shouldReceive('where')->andReturnSelf();
        $trials->shouldReceive('first')->twice()->andReturn((object) ['status' => 'consumed']);
        DB::shouldReceive('table')->with('membership_trials')->twice()->andReturn($trials);
        $access = Mockery::mock(MembershipAccessService::class);
        $access->shouldReceive('snapshot')->once()->with($user)->andReturn(['access_status' => 'none']);
        $request = Request::create('/api/v1/trial-checkouts', 'POST', [
            'selected_package' => 'standard', 'payment_path' => 'monthly',
        ]);
        $request->headers->set('Idempotency-Key', '8b93b51c-e009-4ed1-b860-08c3491b5a24');
        $request->setUserResolver(fn () => $user);

        $response = (new MembershipController($access, new MembershipProductCatalog()))
            ->trialCheckout($request, Mockery::mock(MembershipReconciliationService::class));

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('The account trial is not available.', $response->getData(true)['message']);
    }

    #[DataProvider('userModels')]
    public function test_reconciliation_accepts_the_login_model_and_reports_upstream_failure(string $model): void
    {
        $user = (new $model())->forceFill(['id' => 42]);
        foreach (['subscriptions', 'account_access'] as $table) {
            $query = Mockery::mock(Builder::class);
            $query->shouldReceive('where')->once()->with('user_id', 42)->andReturnSelf();
            $query->shouldReceive('value')->once()->with('updated_at')->andReturnNull();
            DB::shouldReceive('table')->with($table)->once()->andReturn($query);
        }
        $revenueCat = Mockery::mock(RevenueCatService::class);
        $revenueCat->shouldReceive('fetchCustomerMembershipState')->once()->andReturn([
            'ok' => false, 'error' => 'Test upstream unavailable',
        ]);
        $this->app->instance(RevenueCatService::class, $revenueCat);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Test upstream unavailable');
        $this->app->make(MembershipReconciliationService::class)->reconcile($user);
    }

    public static function userModels(): array
    {
        return ['authenticated User' => [User::class], 'legacy Users' => [Users::class]];
    }
}
