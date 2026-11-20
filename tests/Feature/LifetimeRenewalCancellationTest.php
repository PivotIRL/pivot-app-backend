<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\Membership\{GooglePlaySubscriptions, LifetimeRenewalCancellation};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Crypt, DB};
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class LifetimeRenewalCancellationTest extends TestCase
{
    use RefreshDatabase;

    private function setupMember(): array
    {
        config(['membership.store_environment' => 'production', 'membership.sandbox_user_ids' => '']);
        $user = User::create(['name' => 'Test', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('test')]);
        DB::table('account_access')->insert(['user_id' => $user->id, 'selected_package' => 'premium', 'effective_package' => 'premium',
            'payment_path' => 'monthly', 'access_status' => 'active', 'access_source' => 'store', 'store_environment' => 'production',
            'product_id' => 'premium_android_monthly:premium-android-monthly', 'access_ends_at' => now()->addMonth(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('verified_purchases')->insert(['user_id' => $user->id, 'store' => 'play_store', 'environment' => 'production',
            'transaction_id' => 'GPA.test..0', 'product_id' => 'premium_android_monthly', 'base_plan_id' => 'premium-android-monthly',
            'package' => 'premium', 'payment_path' => 'monthly', 'renewal_status' => 'renewing', 'expires_at' => now()->addMonth(), 'created_at' => now(), 'updated_at' => now()]);
        $play = Mockery::mock(GooglePlaySubscriptions::class);
        $play->shouldReceive('ready')->andReturn(true);
        $this->app->instance(GooglePlaySubscriptions::class, $play);
        Sanctum::actingAs($user);
        return [$user, $play, (string) Str::uuid()];
    }

    private function purchase(bool $renew = true): array
    {
        return ['lineItems' => [['productId' => 'premium_android_monthly', 'offerDetails' => ['basePlanId' => 'premium-android-monthly'],
            'latestSuccessfulOrderId' => 'GPA.test..1', 'expiryTime' => now()->addMonth()->toIso8601String(), 'autoRenewingPlan' => ['autoRenewEnabled' => $renew]]]];
    }

    private function consent(string $key): void
    {
        $this->postJson('/api/v1/membership/lifetime-renewal', ['checkout_key' => $key, 'selected_package' => 'premium',
            'purchase_token' => 'secret-test-token', 'consent' => true])->assertNoContent();
        $this->postJson('/api/v1/membership/change-checkouts', ['selected_package' => 'premium', 'payment_path' => 'lifetime'],
            ['Idempotency-Key' => $key])->assertCreated()->assertJsonPath('cancel_renewal_after_purchase', true)->assertJsonPath('renewal_stopped', false);
    }

    private function complete(User $user, string $key): void
    {
        DB::table('membership_attempts')->where('idempotency_key', $key)->update(['status' => 'completed', 'verified_transaction_id' => 'GPA.lifetime']);
        DB::table('verified_purchases')->insert(['user_id' => $user->id, 'store' => 'play_store', 'environment' => 'production',
            'transaction_id' => 'GPA.lifetime', 'product_id' => 'premium_android_lifetime', 'package' => 'premium', 'payment_path' => 'lifetime', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_token_is_encrypted_and_no_cancellation_before_verified_payment(): void
    {
        [$user, $play, $key] = $this->setupMember();
        $play->shouldReceive('get')->once()->with('secret-test-token')->andReturn($this->purchase());
        $play->shouldNotReceive('stopRenewal');
        $this->consent($key);
        $row = DB::table('lifetime_renewal_cancellations')->first();
        $this->assertNotSame('secret-test-token', $row->encrypted_token);
        $this->assertSame('secret-test-token', Crypt::decryptString($row->encrypted_token));
        $service = app(LifetimeRenewalCancellation::class);
        $service->process($row->id);
        DB::table('membership_attempts')->where('idempotency_key', $key)->update(['status' => 'cancelled']);
        $service->process($row->id);
        $this->assertDatabaseHas('lifetime_renewal_cancellations', ['id' => $row->id, 'attempts' => 0]);
        $this->getJson('/api/v1/membership/lifetime-renewal')->assertOk()->assertJsonPath('pending', false);
    }

    public function test_verified_lifetime_cancels_once_and_deletes_token(): void
    {
        [$user, $play, $key] = $this->setupMember();
        $play->shouldReceive('get')->times(3)->andReturn($this->purchase(), $this->purchase(), $this->purchase(false));
        $play->shouldReceive('stopRenewal')->once()->with('secret-test-token');
        $this->consent($key);
        $this->complete($user, $key);
        $this->getJson('/api/v1/membership/lifetime-renewal')->assertJsonPath('pending', true);
        \Illuminate\Support\Facades\Queue::fake();
        $this->artisan('membership:cancel-lifetime-renewals')->assertSuccessful();
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\StopRenewalAfterLifetime::class, 1);
        $id = DB::table('lifetime_renewal_cancellations')->value('id');
        app(LifetimeRenewalCancellation::class)->process($id);
        app(LifetimeRenewalCancellation::class)->process($id);
        $this->assertDatabaseHas('lifetime_renewal_cancellations', ['id' => $id, 'status' => 'completed', 'encrypted_token' => null]);
        $this->getJson('/api/v1/membership/lifetime-renewal')->assertJsonPath('pending', false)->assertJsonPath('completed', true);
    }

    public function test_failed_cancellation_retries_without_repeating_confirmed_cancellation(): void
    {
        [$user, $play, $key] = $this->setupMember();
        $play->shouldReceive('get')->times(3)->andReturn($this->purchase(), $this->purchase(), $this->purchase(false));
        $play->shouldReceive('stopRenewal')->once()->andThrow(new \RuntimeException('network failure'));
        $this->consent($key);
        $this->complete($user, $key);
        $id = DB::table('lifetime_renewal_cancellations')->value('id');
        app(LifetimeRenewalCancellation::class)->process($id);
        $this->assertDatabaseHas('lifetime_renewal_cancellations', ['id' => $id, 'status' => 'retrying']);
        app(LifetimeRenewalCancellation::class)->process($id); // Backoff prevents an immediate retry.
        $this->travel(2)->minutes();
        app(LifetimeRenewalCancellation::class)->process($id);
        $this->assertDatabaseHas('lifetime_renewal_cancellations', ['id' => $id, 'status' => 'completed', 'attempts' => 2]);
    }

    public function test_wrong_environment_or_revoked_lifetime_cannot_cancel(): void
    {
        [$user, $play, $key] = $this->setupMember();
        $play->shouldReceive('get')->once()->andReturn($this->purchase());
        $play->shouldNotReceive('stopRenewal');
        $this->consent($key);
        $this->complete($user, $key);
        $id = DB::table('lifetime_renewal_cancellations')->value('id');
        DB::table('verified_purchases')->where('payment_path', 'lifetime')->update(['environment' => 'sandbox']);
        app(LifetimeRenewalCancellation::class)->process($id);
        DB::table('verified_purchases')->where('payment_path', 'lifetime')->update(['environment' => 'production', 'revoked_at' => now()]);
        app(LifetimeRenewalCancellation::class)->process($id);
        $this->assertDatabaseHas('lifetime_renewal_cancellations', ['id' => $id, 'attempts' => 0]);
    }

    public function test_another_accounts_token_and_missing_consent_are_rejected(): void
    {
        [$user, $play, $key] = $this->setupMember();
        $wrong = $this->purchase();
        $wrong['lineItems'][0]['latestSuccessfulOrderId'] = 'GPA.someone-else';
        $play->shouldReceive('get')->once()->andReturn($wrong);
        $play->shouldNotReceive('stopRenewal');
        $body = ['checkout_key' => $key, 'selected_package' => 'premium', 'purchase_token' => 'secret-test-token'];
        $this->postJson('/api/v1/membership/lifetime-renewal', $body)->assertUnprocessable();
        $this->postJson('/api/v1/membership/lifetime-renewal', $body + ['consent' => true])->assertStatus(409);
        $this->assertDatabaseCount('lifetime_renewal_cancellations', 0);
    }

    public function test_token_validation_rejects_wrong_environment_product_and_installments(): void
    {
        [$user, $play] = $this->setupMember();
        $receipt = DB::table('verified_purchases')->first();
        $service = app(LifetimeRenewalCancellation::class);
        $sandbox = $this->purchase() + ['testPurchase' => []];
        $wrongProduct = $this->purchase();
        $wrongProduct['lineItems'][0]['productId'] = 'another-product';
        $installment = $this->purchase();
        $installment['lineItems'][0]['autoRenewingPlan']['installmentDetails'] = [];
        foreach ([$sandbox, $wrongProduct, $installment] as $invalid) {
            try { $service->validateToken($invalid, $receipt, 'production'); $this->fail('Invalid token accepted'); }
            catch (\RuntimeException $e) { $this->assertNotEmpty($e->getMessage()); }
        }
        $this->assertSame('premium_android_monthly', $service->validateToken($sandbox, $receipt, 'sandbox')['productId']);
    }

    public function test_renewal_receipts_for_same_subscription_are_not_counted_twice(): void
    {
        [$user, $play, $key] = $this->setupMember();
        $receipt = (array) DB::table('verified_purchases')->first();
        unset($receipt['id']);
        $receipt['transaction_id'] = 'GPA.test..1';
        DB::table('verified_purchases')->insert($receipt);
        $play->shouldReceive('get')->once()->andReturn($this->purchase());
        $this->consent($key);
    }
}
