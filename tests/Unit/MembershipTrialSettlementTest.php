<?php

namespace Tests\Unit;

use App\Models\Users;
use App\Services\Membership\MembershipAttemptStatus;
use App\Services\Membership\MembershipProductCatalog;
use App\Services\Membership\MembershipReconciliationService;
use App\Services\Membership\MembershipTrialReservation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class MembershipTrialSettlementTest extends TestCase
{
    public function test_renewed_and_expired_store_purchases_close_the_original_trial_checkout(): void
    {
        $choice = (new MembershipProductCatalog())->checkoutProduct('standard', 'monthly');
        foreach (['active', 'expired'] as $status) {
            $attempt = (object) ['id' => 12, 'status' => 'pending', 'quote' => json_encode([
                'product_id' => $choice['store_product_id'], 'base_plan_id' => $choice['base_plan_id'],
            ])];
            $query = Mockery::mock(Builder::class);
            $query->shouldReceive('where')->andReturnSelf();
            $query->shouldReceive('whereIn')->andReturnSelf();
            $query->shouldReceive('latest')->andReturnSelf();
            $query->shouldReceive('get')->once()->andReturn(collect([$attempt]));
            DB::shouldReceive('table')->with('membership_attempts')->once()->andReturn($query);
            $trials = Mockery::mock(Builder::class);
            $trials->shouldReceive('updateOrInsert')->once()->with(['user_id' => 42], Mockery::on(fn ($data) =>
                $data['status'] === 'consumed' && $data['reservation_expires_at'] === null
                && $data['reserved_at'] === null && $data['transaction_id'] === 'verified-order'));
            DB::shouldReceive('table')->with('membership_trials')->once()->andReturn($trials);
            $transitions = Mockery::mock(MembershipAttemptStatus::class);
            $transitions->shouldReceive('transition')->once()
                ->with(12, 'completed', 'verified-order', 'verified_revenuecat_trial')->andReturnTrue();
            $this->app->instance(MembershipAttemptStatus::class, $transitions);

            $result = (new ReflectionMethod(MembershipReconciliationService::class, 'settleVerifiedTrialCheckout'))
                ->invoke($this->app->make(MembershipReconciliationService::class),
                    (new Users())->forceFill(['id' => 42]),
                    ['status' => $status, 'store_subscription_identifier' => 'verified-order',
                        'starts_at' => now()->subHour()->getTimestampMs(),
                        'current_period_starts_at' => now()->subMinutes(5)->getTimestampMs()], $choice);

            $this->assertSame($attempt, $result);
        }
    }

    public function test_missing_store_transaction_cannot_consume_a_trial(): void
    {
        DB::shouldReceive('table')->never();
        $result = (new ReflectionMethod(MembershipReconciliationService::class, 'settleVerifiedTrialCheckout'))
            ->invoke($this->app->make(MembershipReconciliationService::class),
                (new Users())->forceFill(['id' => 42]), ['status' => 'active'], []);
        $this->assertNull($result);
    }

    public function test_only_expired_reserved_trials_are_released_after_verification(): void
    {
        foreach ([
            ['reserved', now()->subMinute(), true],
            ['reserved', null, true],
            ['reserved', now()->addMinutes(5), false],
            ['consumed', now()->subMinute(), false],
        ] as [$status, $expiry, $release]) {
            $query = Mockery::mock(Builder::class);
            $query->shouldReceive('where')->andReturnSelf();
            $query->shouldReceive('lockForUpdate')->once()->andReturnSelf();
            $query->shouldReceive('first')->once()->andReturn((object) [
                'status' => $status, 'reservation_expires_at' => $expiry,
            ]);
            if ($release) {
                $query->shouldReceive('update')->once()->with(Mockery::on(fn ($data) =>
                    $data['status'] === 'eligible' && $data['reserved_at'] === null
                    && $data['reservation_expires_at'] === null));
            } else {
                $query->shouldReceive('update')->never();
            }
            DB::shouldReceive('table')->with('membership_trials')->andReturn($query)->byDefault();
            $this->app->make(MembershipTrialReservation::class)->releaseExpiredAfterVerification(42);
            $this->addToAssertionCount(1);
        }
    }

    public function test_explicit_cancellation_clears_reserved_checkout_and_retains_late_receipt_recovery(): void
    {
        $trials = Mockery::mock(Builder::class);
        $trials->shouldReceive('where')->andReturnSelf();
        $trials->shouldReceive('lockForUpdate')->once()->andReturnSelf();
        $trials->shouldReceive('first')->once()->andReturn((object) ['status' => 'reserved']);
        $trials->shouldReceive('update')->once()->with(Mockery::on(fn ($data) =>
            $data['status'] === 'eligible' && $data['reservation_expires_at'] === null));
        DB::shouldReceive('table')->with('membership_trials')->andReturn($trials);
        $attempts = Mockery::mock(Builder::class);
        $attempts->shouldReceive('where')->andReturnSelf();
        $attempts->shouldReceive('get')->once()->with(['id'])->andReturn(collect([(object) ['id' => 12]]));
        DB::shouldReceive('table')->with('membership_attempts')->once()->andReturn($attempts);
        $transitions = Mockery::mock(MembershipAttemptStatus::class);
        $transitions->shouldReceive('transition')->once()->with(12, 'cancelled', null,
            'complete_revenuecat_reconciliation_found_no_matching_purchase')->andReturnTrue();
        $this->app->instance(MembershipAttemptStatus::class, $transitions);

        $this->app->make(MembershipTrialReservation::class)->cancelUnpaidAfterVerification(42);
        (new MembershipAttemptStatus())->validateTransition('cancelled', 'completed', 'late-verified-order', 'verified_revenuecat_trial');
        $this->addToAssertionCount(1);
    }

    public function test_consumed_trial_cannot_be_reset_by_checkout_cancellation(): void
    {
        $trials = Mockery::mock(Builder::class);
        $trials->shouldReceive('where')->once()->with('user_id', 42)->andReturnSelf();
        $trials->shouldReceive('lockForUpdate')->once()->andReturnSelf();
        $trials->shouldReceive('first')->once()->andReturn((object) ['status' => 'consumed']);
        $trials->shouldReceive('update')->never();
        DB::shouldReceive('table')->with('membership_trials')->once()->andReturn($trials);
        $this->app->make(MembershipTrialReservation::class)->cancelUnpaidAfterVerification(42);
        $this->addToAssertionCount(1);
    }

    public function test_cancellation_never_releases_reservation_when_revenuecat_is_unavailable(): void
    {
        $user = (new Users())->forceFill(['id' => 42]);
        $request = \Illuminate\Http\Request::create('/api/v1/trial-checkouts/cancel', 'POST');
        $request->setUserResolver(fn () => $user);
        $reconciliation = Mockery::mock(MembershipReconciliationService::class);
        $reconciliation->shouldReceive('reconcile')->once()->with($user)->andThrow(new \RuntimeException('Verification unavailable'));
        DB::shouldReceive('transaction')->never();
        DB::shouldReceive('table')->never();
        $response = $this->app->make(\App\Http\Controllers\Api\V1\MembershipController::class)
            ->cancelTrialCheckout($request, $reconciliation);
        $this->assertSame(503, $response->getStatusCode());
    }
}
