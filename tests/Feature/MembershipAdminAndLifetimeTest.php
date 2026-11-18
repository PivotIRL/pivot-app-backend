<?php
namespace Tests\Feature;

use App\Models\{User, Admin, Tier, Subscription};
use App\Services\Membership\MembershipReconciliationService;
use App\Services\RevenueCatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class MembershipAdminAndLifetimeTest extends TestCase
{
    use RefreshDatabase;

    private function member(): User
    {
        config(['membership.store_environment' => 'production', 'membership.sandbox_user_ids' => '']);
        $user = User::create(['name' => 'Membership test', 'email' => uniqid().'@example.test', 'password' => bcrypt('test')]);
        DB::table('account_access')->insert(['user_id' => $user->id, 'selected_package' => 'premium',
            'effective_package' => 'premium', 'payment_path' => 'monthly', 'access_status' => 'active',
            'access_source' => 'store', 'store_environment' => 'production',
            'product_id' => 'premium_android_monthly:premium-android-monthly',
            'access_ends_at' => now()->addMonth(), 'created_at' => now(), 'updated_at' => now()]);
        return $user;
    }

    public function test_admin_uses_current_membership_instead_of_old_tier(): void
    {
        $user = $this->member();
        $tier = Tier::firstOrCreate(['name' => '1']);
        Subscription::create(['user_id' => $user->id, 'tier_id' => $tier->id, 'active' => true]);
        $admin = Admin::create(['name' => 'Admin', 'email' => uniqid().'@example.test', 'password' => bcrypt('test')]);
        $this->actingAs($admin, 'admin')->get('/dashboard/users/'.$user->id)->assertOk()
            ->assertSee('Premium Monthly')->assertSee('Status: Active')->assertSee('Environment: Production')
            ->assertDontSee('Tier: 1');
        DB::table('account_access')->where('user_id', $user->id)->update(['access_ends_at' => now()->subDay()]);
        $this->get('/dashboard/users/'.$user->id)->assertOk()->assertSee('Status: Expired')->assertDontSee('Active Subscription');
    }

    public function test_lifetime_requires_cancellation_then_quotes_one_time_purchase(): void
    {
        $user = $this->member();
        DB::table('verified_purchases')->insert(['user_id' => $user->id, 'store' => 'play_store', 'environment' => 'production',
            'transaction_id' => 'lifetime-test', 'product_id' => 'premium_android_monthly', 'base_plan_id' => 'premium-android-monthly',
            'package' => 'premium', 'payment_path' => 'monthly', 'renewal_status' => 'renewing', 'expires_at' => now()->addMonth(),
            'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($user);
        $body = ['selected_package' => 'premium', 'payment_path' => 'lifetime'];
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $this->postJson('/api/v1/membership/change-checkouts', $body, $headers)->assertStatus(409)
            ->assertJsonPath('code', 'renewal_cancellation_required');
        $this->assertDatabaseCount('membership_attempts', 0);
        DB::table('verified_purchases')->where('user_id', $user->id)->update(['renewal_status' => 'cancelled']);
        $this->postJson('/api/v1/membership/change-checkouts', $body, $headers)->assertCreated()
            ->assertJsonPath('replacement_mode', 'ONE_TIME')->assertJsonPath('renewal_stopped', true)
            ->assertJsonPath('product_id', 'premium_android_lifetime');
    }

    public function test_concurrent_reconciliation_refetches_instead_of_applying_stale_state(): void
    {
        $user = $this->member();
        $calls = 0;
        $rc = Mockery::mock(RevenueCatService::class);
        $rc->shouldReceive('fetchCustomerMembershipState')->twice()->andReturnUsing(function () use ($user, &$calls) {
            if (++$calls === 1) DB::table('account_access')->where('user_id', $user->id)->update(['updated_at' => now()->addSecond()]);
            return ['ok' => true, 'subscriptions' => [], 'purchases' => []];
        });
        $this->app->instance(RevenueCatService::class, $rc);
        app(MembershipReconciliationService::class)->reconcile($user);
        $this->assertSame(2, $calls);
        $this->assertDatabaseHas('account_access', ['user_id' => $user->id, 'access_status' => 'expired']);
    }
}
