<?php

namespace Tests\Unit;

use App\Http\Controllers\RevenueCatWebhookController;
use App\Models\Users;
use App\Services\Membership\MembershipProductCatalog;
use App\Services\Membership\MembershipReconciliationService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Mockery;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class MembershipAttemptRecoveryTest extends TestCase
{
    public function test_current_checkout_is_not_blocked_by_abandoned_quotes_and_ambiguity_still_fails_closed(): void
    {
        $choice = (new MembershipProductCatalog())->checkoutProduct('premium', 'monthly');
        foreach ([MembershipReconciliationService::class, RevenueCatWebhookController::class] as $class) {
            foreach ([
                [['cancelled', 'pending'], 2],
                [['cancelled', 'scheduled'], 2],
                [['cancelled'], 1],
                [['cancelled', 'cancelled'], null],
                [['pending', 'pending'], null],
            ] as [$statuses, $expected]) {
                $attempts = collect($statuses)->map(fn ($status, $index) => (object) [
                    'id' => $index + 1, 'status' => $status,
                    'quote' => json_encode(['product_id' => $choice['store_product_id'], 'base_plan_id' => $choice['base_plan_id']]),
                ]);
                $query = Mockery::mock(Builder::class);
                $query->shouldReceive('where')->andReturnSelf();
                $query->shouldReceive('whereIn')->andReturnSelf();
                $query->shouldReceive('latest')->andReturnSelf();
                $query->shouldReceive('get')->once()->andReturn($attempts);
                DB::shouldReceive('table')->with('membership_attempts')->once()->andReturn($query);
                $instance = $class === MembershipReconciliationService::class
                    ? $this->app->make($class) : (new ReflectionClass($class))->newInstanceWithoutConstructor();
                $args = $class === MembershipReconciliationService::class
                    ? ['change', $choice] : [$choice, ['change']];
                $result = (new ReflectionMethod($class, 'matchingAttempt'))->invoke($instance,
                    (new Users())->forceFill(['id' => 42]), ...$args);
                $this->assertSame($expected, $result?->id);
            }
        }
    }
}
