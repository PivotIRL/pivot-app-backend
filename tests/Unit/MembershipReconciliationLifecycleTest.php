<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\Users;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Services\Membership\MembershipReconciliationService;
use App\Services\Membership\MembershipProductCatalog;
use App\Services\Membership\VerifiedMembershipProductResolver;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class MembershipReconciliationLifecycleTest extends TestCase
{
    #[DataProvider('userModels')]
    public function test_unknown_active_membership_aborts_instead_of_retiring_access(string $model): void
    {
        $products = Mockery::mock(VerifiedMembershipProductResolver::class);
        $products->shouldReceive('resolve')->once()->andReturnNull();
        $products->shouldReceive('logUnresolved')->once();
        $this->app->instance(VerifiedMembershipProductResolver::class, $products);
        DB::shouldReceive('table')->never();
        $this->expectException(\RuntimeException::class);
        (new ReflectionMethod(MembershipReconciliationService::class, 'subscriptionCandidate'))->invoke(
            $this->app->make(MembershipReconciliationService::class), (new $model())->forceFill(['id' => 42]),
            ['environment' => 'production', 'gives_access' => true, 'store_product_id' => 'unknown-plan'], 'production');
    }

    #[DataProvider('userModels')]
    public function test_unrelated_owned_challenge_pack_does_not_break_membership_reconciliation(string $model): void
    {
        $products = Mockery::mock(VerifiedMembershipProductResolver::class);
        $products->shouldReceive('resolve')->once()->andReturnNull();
        $products->shouldReceive('logUnresolved')->once();
        $this->app->instance(VerifiedMembershipProductResolver::class, $products);
        DB::shouldReceive('table')->never();
        $this->assertNull((new ReflectionMethod(MembershipReconciliationService::class, 'purchaseCandidate'))->invoke(
            $this->app->make(MembershipReconciliationService::class), (new $model())->forceFill(['id' => 42]),
            ['environment' => 'production', 'status' => 'owned', 'store_product_id' => 'challenge-pack'], 'production'));
    }

    #[DataProvider('userModels')]
    public function test_complete_snapshot_rebuilds_owned_lifetime_access_after_retiring_old_cache(string $model): void
    {
        $purchases = Mockery::mock(Builder::class);
        $purchases->shouldReceive('where')->andReturnSelf();
        $purchases->shouldReceive('update')->once()->ordered()->andReturn(1);
        $purchases->shouldReceive('updateOrInsert')->once()->ordered()->with(
            ['store' => 'play_store', 'environment' => 'production', 'transaction_id' => 'lifetime-order'],
            Mockery::on(fn ($data) => $data['revoked_at'] === null && $data['payment_path'] === 'lifetime')
        )->andReturnTrue();
        DB::shouldReceive('table')->with('verified_purchases')->andReturn($purchases);
        $attempts = Mockery::mock(Builder::class);
        $attempts->shouldReceive('where', 'whereIn', 'whereNotNull', 'latest')->andReturnSelf();
        $attempts->shouldReceive('get')->andReturn(collect());
        DB::shouldReceive('table')->with('membership_attempts')->andReturn($attempts);
        $access = Mockery::mock(Builder::class);
        $access->shouldReceive('updateOrInsert')->once()->with(['user_id' => 42], Mockery::on(fn ($data) =>
            $data['effective_package'] === 'standard' && $data['access_status'] === 'active'
            && $data['payment_path'] === 'lifetime' && $data['access_ends_at'] === null))->andReturnTrue();
        DB::shouldReceive('table')->with('account_access')->once()->andReturn($access);
        (new ReflectionMethod(MembershipReconciliationService::class, 'applyState'))->invoke(
            $this->app->make(MembershipReconciliationService::class), (new $model())->forceFill(['id' => 42]),
            ['subscriptions' => [], 'purchases' => [[
                'environment' => 'production', 'status' => 'owned', 'store' => 'play_store',
                'store_product_id' => 'standard_android_lifetime', 'store_purchase_identifier' => 'lifetime-order',
            ]]], 'production');
        $this->assertTrue(true);
    }

    #[DataProvider('userModels')]
    public function test_verified_grace_grants_access_but_hold_and_expiry_do_not(string $model): void
    {
        $choice = (new MembershipProductCatalog())->checkoutProduct('premium', 'monthly');
        $attempts = Mockery::mock(Builder::class);
        $attempts->shouldReceive('where', 'whereIn', 'latest')->andReturnSelf();
        $attempts->shouldReceive('get')->andReturn(collect());
        DB::shouldReceive('table')->with('membership_attempts')->andReturn($attempts);
        $purchases = Mockery::mock(Builder::class);
        $purchases->shouldReceive('updateOrInsert')->andReturnTrue();
        DB::shouldReceive('table')->with('verified_purchases')->andReturn($purchases);
        $method = new ReflectionMethod(MembershipReconciliationService::class, 'subscriptionCandidate');
        $service = $this->app->make(MembershipReconciliationService::class);
        $item = ['environment' => 'production', 'store_product_id' => $choice['product_id'],
            'store_subscription_identifier' => 'order-1', 'gives_access' => true,
            'current_period_ends_at' => now()->addDay()->getTimestampMs()];
        foreach (['active', 'in_grace_period', 'in_billing_retry'] as $status) {
            $result = $method->invoke($service, (new $model())->forceFill(['id' => 42]), $item + ['status' => $status], 'production');
            $this->assertSame('active', $result['status']);
            $this->assertSame('premium', $result['effective_package']);
        }
        $this->assertNull($method->invoke($service, (new $model())->forceFill(['id' => 42]),
            array_replace($item, ['status' => 'in_billing_retry', 'gives_access' => false]), 'production'));
        $this->assertNull($method->invoke($service, (new $model())->forceFill(['id' => 42]),
            array_replace($item, ['current_period_ends_at' => now()->subMinute()->getTimestampMs()]), 'production'));
    }

    #[DataProvider('userModels')]
    public function test_complete_empty_snapshot_retires_cached_ownership_and_store_access(string $model): void
    {
        $purchases = Mockery::mock(Builder::class);
        $purchases->shouldReceive('where')->with('user_id', 42)->once()->andReturnSelf();
        $purchases->shouldReceive('where')->with('environment', 'production')->once()->andReturnSelf();
        $purchases->shouldReceive('update')->once()->with(Mockery::on(fn ($data) => $data['revoked_at'] !== null))->andReturn(1);
        DB::shouldReceive('table')->with('verified_purchases')->once()->andReturn($purchases);
        $attempts = Mockery::mock(Builder::class);
        $attempts->shouldReceive('where', 'whereNotNull')->andReturnSelf();
        $attempts->shouldReceive('get')->andReturn(collect());
        DB::shouldReceive('table')->with('membership_attempts')->andReturn($attempts);
        $access = Mockery::mock(Builder::class);
        $access->shouldReceive('where')->with('user_id', 42)->andReturnSelf();
        $access->shouldReceive('first')->once()->andReturn((object) ['access_source' => 'store', 'access_ends_at' => null]);
        $access->shouldReceive('update')->once()->with(Mockery::on(fn ($data) =>
            $data['access_status'] === 'expired' && $data['effective_package'] === null))->andReturn(1);
        DB::shouldReceive('table')->with('account_access')->andReturn($access);
        (new ReflectionMethod(MembershipReconciliationService::class, 'applyState'))->invoke(
            $this->app->make(MembershipReconciliationService::class), (new $model())->forceFill(['id' => 42]),
            ['subscriptions' => [], 'purchases' => []], 'production');
        $this->assertTrue(true);
    }

    public static function userModels(): array
    {
        return ['authenticated User' => [User::class], 'legacy Users' => [Users::class]];
    }
}
