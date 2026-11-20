<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Membership\MembershipAccessService;
use App\Services\Membership\MembershipReconciliationService;
use App\Services\RevenueCatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MembershipEnvironmentRoutingTest extends TestCase
{
    use RefreshDatabase;

    public static function environments(): array
    {
        return ['production customer' => ['production', false, 'production'],
            'production tester' => ['production', true, 'sandbox'],
            'staging customer' => ['sandbox', false, 'sandbox']];
    }

    #[DataProvider('environments')]
    public function test_reconciled_monthly_purchase_can_upgrade_in_its_selected_environment(string $default, bool $tester, string $expected): void
    {
        $user = User::create(['name' => 'Environment test', 'email' => uniqid().'@example.test', 'password' => bcrypt('test')]);
        config(['membership.store_environment' => $default, 'membership.sandbox_user_ids' => $tester ? (string) $user->id : '']);
        $service = Mockery::mock(RevenueCatService::class);
        $service->shouldReceive('fetchCustomerMembershipState')->once()->with((string) $user->id, $expected)->andReturn([
            'ok' => true, 'purchases' => [], 'subscriptions' => [[
                'environment' => $expected, 'store' => 'play_store', 'status' => 'active', 'gives_access' => true,
                'store_product_id' => 'standard_android_monthly', 'base_plan_id' => 'standard-android-monthly',
                'store_subscription_identifier' => 'environment-test-transaction', 'ends_at' => now()->addMonth()->getTimestampMs(),
                'auto_renewal_status' => 'will_renew',
                'entitlements' => ['items' => [['state' => 'active', 'lookup_key' => 'standard_andriod']]],
            ]],
        ]);
        $this->app->instance(RevenueCatService::class, $service);
        app(MembershipReconciliationService::class)->reconcile($user);
        $this->assertDatabaseHas('account_access', ['user_id' => $user->id, 'access_status' => 'active', 'store_environment' => $expected]);
        $this->assertDatabaseHas('verified_purchases', ['user_id' => $user->id, 'environment' => $expected, 'payment_path' => 'monthly']);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/membership/change-checkouts', ['selected_package' => 'premium', 'payment_path' => 'monthly'],
            ['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()])->assertCreated()
            ->assertJsonPath('product_id', 'premium_android_monthly')
            ->assertJsonPath('replacement_mode', 'CHARGE_PRORATED_PRICE');

        if ($tester) {
            config(['membership.sandbox_user_ids' => '']);
            $this->assertNull(app(MembershipAccessService::class)->snapshot($user->fresh())['effective_package']);
        }
    }

    public function test_allowlisted_account_receives_sandbox_webhook_and_lifecycle_reconciliation(): void
    {
        $user = User::create(['name' => 'Sandbox tester', 'email' => uniqid().'@example.test', 'password' => bcrypt('test')]);
        config(['membership.store_environment' => 'production', 'membership.sandbox_user_ids' => (string) $user->id,
            'services.revenuecat.webhook_secret' => 'test-secret']);
        foreach (['1', '2', '3'] as $name) \App\Models\Tier::firstOrCreate(['name' => $name]);
        $event = ['id' => 'allowed-sandbox', 'type' => 'INITIAL_PURCHASE', 'app_user_id' => (string) $user->id,
            'environment' => 'SANDBOX', 'store' => 'PLAY_STORE', 'product_id' => 'standard_android_monthly',
            'base_plan_id' => 'standard-android-monthly', 'transaction_id' => 'allowed-sandbox-tx',
            'entitlement_ids' => ['standard_andriod'], 'expiration_at_ms' => now()->addMonth()->getTimestampMs()];
        $this->postJson('/api/v1/webhooks/revenuecat', ['event' => $event], ['Authorization' => 'Bearer test-secret'])->assertOk();
        $this->assertDatabaseHas('account_access', ['user_id' => $user->id, 'store_environment' => 'sandbox', 'access_status' => 'active']);
        $this->assertDatabaseHas('verified_purchases', ['user_id' => $user->id, 'environment' => 'sandbox']);
        $reconciler = Mockery::mock(MembershipReconciliationService::class);
        $reconciler->shouldReceive('reconcile')->once()->with(Mockery::on(fn ($account) => $account->id === $user->id));
        $this->app->instance(MembershipReconciliationService::class, $reconciler);
        $event['id'] = 'allowed-sandbox-lifecycle';
        $event['type'] = 'REFUND_REVERSED';
        $this->postJson('/api/v1/webhooks/revenuecat', ['event' => $event], ['Authorization' => 'Bearer test-secret'])->assertOk();
    }

    public function test_sandbox_webhook_cannot_modify_normal_production_account(): void
    {
        config(['membership.store_environment' => 'production', 'membership.sandbox_user_ids' => '', 'services.revenuecat.webhook_secret' => 'test-secret']);
        $user = User::create(['name' => 'Environment test', 'email' => uniqid().'@example.test', 'password' => bcrypt('test')]);
        foreach (['INITIAL_PURCHASE', 'REFUND_REVERSED'] as $type) {
            $this->postJson('/api/v1/webhooks/revenuecat', ['event' => [
                'id' => 'wrong-env-'.$type, 'type' => $type, 'app_user_id' => (string) $user->id,
                'environment' => 'SANDBOX', 'store' => 'PLAY_STORE', 'product_id' => 'standard_android_monthly',
                'transaction_id' => 'sandbox-tx', 'entitlement_ids' => ['standard_andriod'],
            ]], ['Authorization' => 'Bearer test-secret'])->assertOk();
        }
        $this->assertDatabaseCount('account_access', 0);
        $this->assertDatabaseCount('verified_purchases', 0);
    }
}
