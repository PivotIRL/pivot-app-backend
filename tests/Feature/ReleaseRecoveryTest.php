<?php

namespace Tests\Feature;

use App\Models\{User, Research, Activity, Hobby, ChallengePacksWebhook};
use App\Services\Membership\{MembershipReconciliationService, MembershipAttemptStatus};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReleaseRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.revenuecat.api_key' => '', 'services.revenuecat.project_id' => '']);
    }

    public function test_receipt_lookup_never_invents_an_unspent_legacy_balance(): void
    {
        $user = $this->user();
        config(['services.revenuecat.api_key' => 'test', 'services.revenuecat.project_id' => 'test',
            'services.revenuecat.api_url' => 'https://receipts.example.test/v2', 'membership.store_environment' => 'production']);
        $url = 'https://receipts.example.test/v2/projects/test/customers/'.$user->id.'/purchases*';
        Http::fake([$url => Http::sequence()->push(['items' => [[
            'store_purchase_identifier' => 'verified-tx', 'product_id' => 'prod-pack',
            'environment' => 'production', 'status' => 'owned', 'ownership' => 'purchased',
        ]]])->push([], 503), 'https://receipts.example.test/v2/projects/test/products/prod-pack' => Http::response([
            'id' => 'prod-pack', 'store_identifier' => '3_challenge_pack',
        ])]);
        $body = ['packs' => [
            ['transaction_id' => 'verified-tx', 'product_id' => 'old-label', 'total' => 99, 'remaining' => 88],
            ['transaction_id' => 'missing-tx', 'product_id' => '3_challenge_pack', 'total' => 3, 'remaining' => 3],
        ]];
        $this->postJson('/api/v1/challenge-pack/reconcile-legacy', $body)->assertOk()
            ->assertJsonPath('data.0.recovery_status', 'receipt_verified_balance_review_required')
            ->assertJsonPath('data.0.pack', null)->assertJsonPath('data.1.recovery_status', 'receipt_not_found');
        $this->assertDatabaseCount('challenge_pack', 0);
        $this->assertDatabaseHas('legacy_pack_reconciliations', ['transaction_id' => 'verified-tx', 'local_remaining' => 88]);
        $this->postJson('/api/v1/challenge-pack/reconcile-legacy', $body)->assertOk()
            ->assertJsonPath('data.0.recovery_status', 'verification_unavailable');
        $this->assertDatabaseCount('legacy_pack_reconciliations', 2);
        $this->assertDatabaseCount('challenge_pack', 0);
    }

    public function test_wrong_environment_refunded_shared_or_unknown_products_do_not_verify_legacy_packs(): void
    {
        $this->user();
        config(['membership.store_environment' => 'production']);
        $base = ['store_purchase_identifier' => 'tx', 'environment' => 'production',
            'status' => 'owned', 'ownership' => 'purchased', 'store_product_id' => '3_challenge_pack'];
        $invalid = [
            ['environment' => 'sandbox'], ['status' => 'refunded'], ['ownership' => 'family_shared'],
            ['store_product_id' => 'unknown'], ['store_purchase_identifier' => 'other-tx'],
        ];
        $this->mock(\App\Services\RevenueCatService::class, fn ($mock) =>
            $mock->shouldReceive('fetchCustomerPackPurchases')->once()->andReturn([
                'ok' => true, 'items' => array_map(fn ($overrides) => array_replace($base, $overrides), $invalid),
            ]));
        $this->postJson('/api/v1/challenge-pack/reconcile-legacy', ['packs' => [[
            'transaction_id' => 'tx', 'product_id' => '3_challenge_pack', 'total' => 3, 'remaining' => 3,
        ]]])->assertOk()->assertJsonPath('data.0.recovery_status', 'receipt_not_found')->assertJsonPath('data.0.pack', null);
        $this->assertDatabaseCount('challenge_pack', 0);
    }

    private function user(): User
    {
        $user = User::create(['name' => 'Recovery test', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('test-only')]);
        Sanctum::actingAs($user);
        return $user;
    }

    private function pack(User $user): ChallengePacksWebhook
    {
        return ChallengePacksWebhook::create(['app_id' => 'test', 'user_id' => $user->id, 'transaction_id' => (string) Str::uuid(),
            'product_id' => '3_challenge_pack', 'price' => 1, 'total' => 3, 'remaining' => 3, 'status' => 'unused']);
    }

    private function hold(ChallengePacksWebhook $pack): array
    {
        $hobby = Hobby::create(['name' => 'Recovery '.Str::uuid()]);
        $activity = Activity::create(['hobby_id' => $hobby->id, 'activity_title' => 'Long challenge',
            'duration_minutes' => '120', 'tier' => '1', 'energy_level' => 'Low']);
        $id = (string) Str::uuid();
        return $this->postJson('/api/v1/challenge-reservations', ['challenge_id' => $activity->id, 'reservation_id' => $id,
            'allocation' => 'pack', 'pack_transaction_id' => $pack->transaction_id], ['Idempotency-Key' => $id])
            ->assertCreated()->assertJsonPath('durable_hold', true)->json();
    }

    public function test_daily_article_and_bookmark_library_share_the_stable_article_id(): void
    {
        $this->user();
        $article = Research::create(['fun_facts' => 'Fact', 'summary' => 'Summary', 'full_content' => 'Premium only', 'files' => 'article.pdf']);
        $this->getJson('/api/v1/daily-article')->assertOk()->assertJsonPath('id', $article->id)->assertJsonPath('full_content', null);
        $this->postJson('/api/v1/profile/article-library', ['article_id' => $article->id])->assertCreated();
        $this->getJson('/api/v1/profile/article-library')->assertOk()->assertJsonPath('0.id', $article->id);
        $this->deleteJson('/api/v1/profile/article-library/'.$article->id)->assertOk();
        $this->getJson('/api/v1/profile/article-library')->assertJsonCount(0);
    }

    public function test_long_pause_and_late_offline_completion_keep_one_pack_debit(): void
    {
        $user = $this->user(); $pack = $this->pack($user); $hold = $this->hold($pack);
        $this->travel(7)->days();
        $this->postJson('/api/v1/challenge-reservations/'.$hold['reservation_id'].'/resume', ['challenge_id' => $hold['challenge_id']])->assertOk();
        // Another device/old client cannot consume the held credit, even after its old lease time.
        $this->patchJson('/api/v1/challenge-pack', ['transaction_id' => $pack->transaction_id, 'usage_count' => 3])->assertNotFound();
        $completion = ['completion_id' => (string) Str::uuid()];
        $url = '/api/v1/challenge-reservations/'.$hold['reservation_id'].'/complete';
        $first = $this->postJson($url, $completion)->assertOk();
        $this->postJson($url, $completion)->assertOk()->assertExactJson($first->json());
        $this->assertSame(2, $pack->fresh()->remaining);
    }

    public function test_abandon_releases_a_durable_slot_but_cannot_refund_a_completion(): void
    {
        $user = $this->user(); $pack = $this->pack($user); $hold = $this->hold($pack);
        $this->travel(2)->days();
        $url = '/api/v1/challenge-reservations/'.$hold['reservation_id'];
        $this->postJson($url.'/abandon')->assertOk();
        $this->postJson($url.'/abandon')->assertOk();
        $this->postJson($url.'/complete', ['completion_id' => (string) Str::uuid()])->assertStatus(409);
        $this->patchJson('/api/v1/challenge-pack', ['transaction_id' => $pack->transaction_id, 'usage_count' => 3])->assertOk();
    }

    public function test_expired_legacy_hold_cannot_take_another_devices_reserved_credit(): void
    {
        $user = $this->user(); $pack = $this->pack($user); $pack->update(['total' => 1, 'remaining' => 1]);
        $old = $this->hold($pack);
        DB::table('challenge_quota_reservations')->where('reservation_id', $old['reservation_id'])
            ->update(['durable_hold' => false, 'expires_at' => now()->subMinute()]);
        $this->hold($pack);
        $this->postJson('/api/v1/challenge-reservations/'.$old['reservation_id'].'/complete', ['completion_id' => (string) Str::uuid()])->assertStatus(409);
        $this->assertSame(1, $pack->fresh()->remaining);
    }

    public function test_expired_legacy_hold_recovers_when_its_credit_is_still_available(): void
    {
        $user = $this->user(); $pack = $this->pack($user); $old = $this->hold($pack);
        DB::table('challenge_quota_reservations')->where('reservation_id', $old['reservation_id'])
            ->update(['durable_hold' => false, 'expires_at' => now()->subDay()]);
        $this->postJson('/api/v1/challenge-reservations/'.$old['reservation_id'].'/resume', ['challenge_id' => $old['challenge_id']])
            ->assertOk()->assertJsonPath('durable_hold', true);
    }

    public function test_reinstalled_device_can_find_and_release_its_own_durable_hold(): void
    {
        $user = $this->user(); $pack = $this->pack($user); $hold = $this->hold($pack);
        $this->travel(30)->days();
        $this->getJson('/api/v1/challenge-reservations')->assertOk()->assertJsonPath('data.0.reservation_id', $hold['reservation_id']);
        $this->user();
        $this->getJson('/api/v1/challenge-reservations')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/challenge-reservations/'.$hold['reservation_id'].'/abandon')->assertNotFound();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/challenge-reservations/'.$hold['reservation_id'].'/abandon')->assertOk();
        $this->getJson('/api/v1/challenge-reservations')->assertJsonCount(0, 'data');
        $this->assertSame(3, $pack->fresh()->remaining);
    }

    public function test_legacy_snapshot_is_archived_without_a_second_debit_or_stale_balance_replay(): void
    {
        $user = $this->user(); $pack = $this->pack($user); $pack->update(['remaining' => 2]);
        $body = ['packs' => [['transaction_id' => $pack->transaction_id, 'product_id' => $pack->product_id, 'total' => 3, 'remaining' => 1]]];
        $this->postJson('/api/v1/challenge-pack/reconcile-legacy', $body)->assertOk()->assertJsonPath('data.0.pack.remaining', 2);
        $this->assertSame(2, $pack->fresh()->remaining);
        $this->patchJson('/api/v1/challenge-pack', ['transaction_id' => $pack->transaction_id, 'usage_count' => 1])->assertOk();
        $this->postJson('/api/v1/challenge-pack/reconcile-legacy', $body)->assertOk()->assertJsonPath('data.0.pack.remaining', 1);
        $this->assertDatabaseCount('legacy_pack_reconciliations', 1);
        $this->assertDatabaseHas('legacy_pack_reconciliations', ['local_remaining' => 1, 'server_remaining' => 2]);
        $this->user();
        $this->postJson('/api/v1/challenge-pack/reconcile-legacy', $body)->assertOk()
            ->assertJsonPath('data.0.resolution', 'receipt_required')->assertJsonPath('data.0.pack', null);
        $this->assertSame(1, $pack->fresh()->remaining);
    }

    public function test_missing_receipt_can_be_reconciled_later_without_trusting_local_credit_metadata(): void
    {
        $user = $this->user(); $transaction = (string) Str::uuid();
        $body = ['packs' => [['transaction_id' => $transaction, 'product_id' => 'old-local-label', 'total' => 99, 'remaining' => 88]]];
        $this->postJson('/api/v1/challenge-pack/reconcile-legacy', $body)->assertOk()->assertJsonPath('data.0.resolution', 'receipt_required');
        $pack = $this->pack($user); $pack->update(['transaction_id' => $transaction]);
        $this->postJson('/api/v1/challenge-pack/reconcile-legacy', $body)->assertOk()
            ->assertJsonPath('data.0.resolution', 'server_balance_preserved')->assertJsonPath('data.0.pack.remaining', 3);
        $this->assertDatabaseCount('legacy_pack_reconciliations', 1);
        $this->assertDatabaseHas('legacy_pack_reconciliations', ['local_remaining' => 88, 'resolution' => 'server_balance_preserved']);
        $this->assertSame(3, $pack->fresh()->remaining);
    }

    private function attempt(User $user, string $status = 'pending'): array
    {
        $id = (string) Str::uuid(); $key = (string) Str::uuid();
        $quote = ['attempt_id' => $id, 'selected_package' => 'standard', 'payment_path' => 'monthly',
            'product_id' => 'standard_android_monthly', 'base_plan_id' => 'standard-android-monthly', 'expires_at' => now()->addMinutes(10)->toISOString()];
        DB::table('membership_attempts')->insert(['user_id' => $user->id, 'attempt_id' => $id, 'operation' => 'purchase',
            'idempotency_key' => $key, 'request_fingerprint' => hash('sha256', json_encode(['selected_package' => 'standard', 'payment_path' => 'monthly'])),
            'quote' => json_encode($quote), 'status' => $status, 'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now()]);
        return ['attempt_id' => $id, 'key' => $key];
    }

    public function test_lost_checkout_key_can_be_recovered_cancelled_and_replaced(): void
    {
        $user = $this->user(); $attempt = $this->attempt($user);
        $this->mock(MembershipReconciliationService::class, fn ($mock) => $mock->shouldReceive('reconcile')->andReturnNull());
        $this->postJson('/api/v1/membership/checkout-recovery')->assertOk()->assertJsonPath('attempts.0.idempotency_key', $attempt['key']);
        $this->postJson('/api/v1/membership/checkouts/cancel', ['attempt_id' => $attempt['attempt_id']])->assertOk()->assertJsonPath('status', 'cancelled');
        $this->postJson('/api/v1/membership/checkouts/cancel', ['attempt_id' => $attempt['attempt_id']])->assertOk();
        $selection = ['selected_package' => 'standard', 'payment_path' => 'monthly'];
        $this->postJson('/api/v1/membership/purchase-checkouts', $selection, ['Idempotency-Key' => $attempt['key']])->assertStatus(409);
        $this->postJson('/api/v1/membership/purchase-checkouts', $selection, ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        // Late verified receipts remain able to settle the retired hold.
        DB::transaction(fn () => app(MembershipAttemptStatus::class)->transition(
            DB::table('membership_attempts')->where('attempt_id', $attempt['attempt_id'])->value('id'),
            'completed', 'verified-late-transaction', 'verified_revenuecat_purchase'));
        $this->postJson('/api/v1/membership/checkouts/cancel', ['attempt_id' => $attempt['attempt_id']])->assertStatus(409);
    }

    public function test_cancellation_fails_closed_when_store_verification_fails(): void
    {
        $user = $this->user(); $attempt = $this->attempt($user);
        $this->mock(MembershipReconciliationService::class, fn ($mock) => $mock->shouldReceive('reconcile')->andThrow(new \RuntimeException('Store unavailable')));
        $this->postJson('/api/v1/membership/checkouts/cancel', ['attempt_id' => $attempt['attempt_id']])->assertStatus(503);
        $this->assertDatabaseHas('membership_attempts', ['attempt_id' => $attempt['attempt_id'], 'status' => 'pending']);
    }

    public function test_other_accounts_cannot_cancel_or_discover_the_hold(): void
    {
        $owner = $this->user(); $attempt = $this->attempt($owner); $this->user();
        $this->mock(MembershipReconciliationService::class, fn ($mock) => $mock->shouldReceive('reconcile')->once()->andReturnNull());
        $this->postJson('/api/v1/membership/checkouts/cancel', ['attempt_id' => $attempt['attempt_id']])->assertNotFound();
        $this->postJson('/api/v1/membership/checkout-recovery', ['idempotency_key' => $attempt['key']])->assertOk()->assertJsonCount(0, 'attempts');
    }
}
