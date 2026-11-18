<?php

namespace Tests\Unit;

use App\Models\Users;
use App\Services\Membership\CompletedMembershipTrial;
use App\Services\Membership\MembershipAttemptStatus;
use App\Services\Membership\MembershipProductCatalog;
use App\Services\Membership\MembershipReconciliationService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class CompletedMembershipTrialTest extends TestCase
{
    public function test_completed_trial_quote_survives_refresh_without_recompleting_attempt(): void
    {
        $choice = (new MembershipProductCatalog())->checkoutProduct('standard', 'annual');
        $completed = (object) ['id' => 9, 'status' => 'completed', 'quote' => json_encode([
            'product_id' => $choice['store_product_id'], 'base_plan_id' => $choice['base_plan_id'],
            'selected_package' => 'standard', 'trial_access_package' => 'premium',
        ])];
        $pending = Mockery::mock(Builder::class);
        $pending->shouldReceive('where', 'whereIn', 'latest')->andReturnSelf();
        $pending->shouldReceive('get')->once()->andReturn(collect());
        $history = Mockery::mock(Builder::class);
        $history->shouldReceive('where')->with('user_id', 42)->once()->andReturnSelf();
        $history->shouldReceive('where')->with('operation', 'trial')->once()->andReturnSelf();
        $history->shouldReceive('where')->with('status', 'completed')->once()->andReturnSelf();
        $history->shouldReceive('whereIn')->with('verified_transaction_id', ['verified-order'])->once()->andReturnSelf();
        $history->shouldReceive('get')->once()->andReturn(collect([$completed]));
        DB::shouldReceive('table')->with('membership_attempts')->twice()->andReturn($pending, $history);
        $trials = Mockery::mock(Builder::class);
        $trials->shouldReceive('updateOrInsert')->once()->andReturnTrue();
        DB::shouldReceive('table')->with('membership_trials')->once()->andReturn($trials);
        $status = Mockery::mock(MembershipAttemptStatus::class);
        $status->shouldNotReceive('transition');
        $this->app->instance(MembershipAttemptStatus::class, $status);
        $result = (new ReflectionMethod(MembershipReconciliationService::class, 'settleVerifiedTrialCheckout'))->invoke(
            $this->app->make(MembershipReconciliationService::class), (new Users())->forceFill(['id' => 42]),
            ['status' => 'trialing', 'store_subscription_identifier' => 'verified-order',
                'current_period_ends_at' => now()->addMinute()->getTimestampMs()], $choice);
        $this->assertSame($completed, $result);
        $this->assertSame('premium', json_decode($result->quote, true)['trial_access_package']);
    }

    public function test_history_requires_transaction_and_exact_base_plan_and_rejects_ambiguity(): void
    {
        $service = new CompletedMembershipTrial();
        $choice = (new MembershipProductCatalog())->checkoutProduct('standard', 'annual');
        $this->assertNull($service->find(42, $choice, []));
        $valid = (object) ['quote' => json_encode(['product_id' => $choice['store_product_id'], 'base_plan_id' => $choice['base_plan_id']])];
        $wrong = (object) ['quote' => json_encode(['product_id' => $choice['store_product_id'], 'base_plan_id' => 'standard-android-annual'])];
        foreach ([[$wrong], [$valid, $valid]] as $records) {
            $query = Mockery::mock(Builder::class);
            $query->shouldReceive('where', 'whereIn')->andReturnSelf();
            $query->shouldReceive('get')->once()->andReturn(collect($records));
            DB::shouldReceive('table')->with('membership_attempts')->once()->andReturn($query);
            $this->assertNull($service->find(42, $choice, ['order']));
        }
    }
}
