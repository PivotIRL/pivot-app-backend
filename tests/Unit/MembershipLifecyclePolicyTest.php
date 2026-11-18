<?php

namespace Tests\Unit;

use App\Http\Requests\RevenueCatWebhookRequest;
use App\Http\Controllers\RevenueCatWebhookController;
use App\Services\Membership\MembershipWebhookPolicy;
use App\Services\Membership\MembershipReconciliationService;
use App\Services\Membership\MembershipAttemptStatus;
use App\Services\Membership\VerifiedMembershipProductResolver;
use App\Services\Membership\MembershipProductCatalog;
use App\Services\Membership\ScheduledMembershipChangePolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Mockery;
use App\Models\Users;
use Illuminate\Database\Query\Builder;
use ReflectionMethod;
use Tests\TestCase;

class MembershipLifecyclePolicyTest extends TestCase
{
    public function test_replacement_quotes_follow_the_billing_contract(): void
    {
        $controller = (new \ReflectionClass(\App\Http\Controllers\Api\V1\MembershipController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($controller, 'replacementMode');
        foreach ([
            ['standard', 'monthly', 'premium', 'monthly', 'CHARGE_PRORATED_PRICE'],
            ['premium', 'monthly', 'standard', 'monthly', 'DEFERRED'],
            ['standard', 'monthly', 'standard', 'annual', 'CHARGE_FULL_PRICE'],
            ['premium', 'annual', 'premium', 'monthly', 'DEFERRED'],
            ['standard', 'annual', 'premium', 'monthly', 'CHARGE_FULL_PRICE'],
            ['standard', 'monthly', 'standard', 'lifetime', 'ONE_TIME'],
            ['standard', 'lifetime', 'premium', 'lifetime', 'ONE_TIME'],
            ['premium', 'lifetime', 'standard', 'monthly', null],
            ['standard', 'annual', 'standard', 'annual', null],
        ] as [$from, $fromPath, $to, $toPath, $expected]) {
            $this->assertSame($expected, $method->invoke($controller, $from, $fromPath, $to, $toPath));
        }
    }

    public function test_refund_and_cancellation_have_different_access_outcomes(): void
    {
        $future = Carbon::now()->addDay();
        $past = Carbon::now()->subDay();
        $this->assertFalse(MembershipWebhookPolicy::revoked('CANCELLATION', 'monthly', $future));
        $this->assertTrue(MembershipWebhookPolicy::revoked('CANCELLATION', 'lifetime', null));
        $this->assertTrue(MembershipWebhookPolicy::revoked('CANCELLATION', 'annual', $past));
        $this->assertTrue(MembershipWebhookPolicy::revoked('REFUND', 'annual', $future));
        $this->assertFalse(MembershipWebhookPolicy::revoked('SUBSCRIPTION_PAUSED', 'monthly', $future));
        $this->assertTrue(MembershipWebhookPolicy::revoked('EXPIRATION', 'monthly', $past));
        $this->assertSame('cancelled', MembershipWebhookPolicy::renewalStatus(['type' => 'CANCELLATION']));
        $this->assertSame('renewing', MembershipWebhookPolicy::renewalStatus(['type' => 'UNCANCELLATION']));
    }

    public function test_lifecycle_payloads_validate_without_inventing_transfer_app_user_id(): void
    {
        $request = new RevenueCatWebhookRequest();
        foreach (MembershipWebhookPolicy::EVENTS as $type) {
            $payload = ['id' => 'event-1', 'type' => $type, 'app_user_id' => '42'];
            if ($type === 'TRANSFER') {
                unset($payload['app_user_id']);
                $payload += ['transferred_from' => ['42'], 'transferred_to' => ['43']];
            }
            if ($type === 'TEST') unset($payload['app_user_id']);
            $request->replace($payload);
            $this->assertTrue(Validator::make($payload, $request->rules())->passes(), $type);
        }
        $this->assertFalse(Validator::make(['id' => 'event', 'type' => 'TRANSFER'], $request->rules())->passes());
        $this->assertFalse(Validator::make(['id' => 'event', 'type' => 'RENEWAL'], $request->rules())->passes());
    }

    public function test_authenticated_dashboard_test_does_not_touch_membership(): void
    {
        config(['services.revenuecat.webhook_secret' => 'test-only']);
        DB::shouldReceive('table')->never();
        $request = new RevenueCatWebhookRequest();
        $request->headers->set('Authorization', 'Bearer test-only');
        $request->setValidator(Validator::make(['id' => 'test', 'type' => 'TEST'], $request->rules()));
        $reconciliation = Mockery::mock(MembershipReconciliationService::class);
        $reconciliation->shouldNotReceive('reconcile');
        $controller = new RevenueCatWebhookController();
        $this->assertSame(200, $controller($request, Mockery::mock(VerifiedMembershipProductResolver::class),
            Mockery::mock(MembershipAttemptStatus::class), $reconciliation)->status());
        $request->headers->set('Authorization', 'Bearer wrong');
        $this->assertSame(401, $controller($request, Mockery::mock(VerifiedMembershipProductResolver::class),
            Mockery::mock(MembershipAttemptStatus::class), $reconciliation)->status());
    }

    public function test_only_verified_terminal_source_can_retire_a_scheduled_change(): void
    {
        $catalog = new MembershipProductCatalog();
        $policy = new ScheduledMembershipChangePolicy($catalog);
        $source = $catalog->checkoutProduct('premium', 'monthly');
        $target = $catalog->checkoutProduct('standard', 'annual');
        $quote = ['old_product_id' => $source['product_id'], 'product_id' => $target['store_product_id'],
            'base_plan_id' => $target['base_plan_id'], 'replacement_mode' => 'DEFERRED'];
        $item = ['environment' => 'sandbox', 'store_product_id' => $source['product_id'],
            'status' => 'expired', 'gives_access' => false, 'ends_at' => 900,
            'auto_renewal_status' => 'will_not_renew', 'pending_changes' => null];
        $this->assertTrue($policy->canCancel($quote, [$item], 'sandbox', 1000));
        $this->assertFalse($policy->canCancel($quote, [], 'sandbox', 1000));
        foreach ([['gives_access' => true], ['ends_at' => 1100], ['status' => 'active'],
            ['auto_renewal_status' => 'will_change_product'], ['environment' => 'production'],
            ['pending_changes' => ['product' => ['store_identifier' => $target['product_id']]]]] as $change) {
            $this->assertFalse($policy->canCancel($quote, [array_replace($item, $change)], 'sandbox', 1000));
        }
        $missing = $item;
        unset($missing['pending_changes']);
        $this->assertFalse($policy->canCancel($quote, [$missing], 'sandbox', 1000));
        $this->assertFalse($policy->canCancel($quote, [$item, array_replace($item,
            ['store_product_id' => $target['product_id']])], 'sandbox', 1000));
    }

    public function test_repeated_deferred_notification_does_not_transition_scheduled_to_scheduled(): void
    {
        config(['membership.store_environment' => 'sandbox']);
        $choice = (new MembershipProductCatalog())->checkoutProduct('standard', 'monthly');
        $products = Mockery::mock(VerifiedMembershipProductResolver::class);
        $products->shouldReceive('resolve')->once()->andReturn($choice);
        $attempts = Mockery::mock(Builder::class);
        $attempts->shouldReceive('where', 'whereIn', 'latest')->andReturnSelf();
        $attempts->shouldReceive('get')->once()->andReturn(collect([(object) [
            'id' => 5, 'status' => 'scheduled', 'quote' => json_encode([
                'product_id' => $choice['store_product_id'], 'base_plan_id' => $choice['base_plan_id'],
                'replacement_mode' => 'DEFERRED',
            ]),
        ]]));
        DB::shouldReceive('table')->with('membership_attempts')->once()->andReturn($attempts);
        $statuses = Mockery::mock(MembershipAttemptStatus::class);
        $statuses->shouldNotReceive('transition');
        $eventState = ['tier_id' => 3, 'active' => true];
        $args = [(new Users())->forceFill(['id' => 42]), ['environment' => 'SANDBOX'], &$eventState,
            'PRODUCT_CHANGE', 'premium_android_monthly', $choice['store_product_id'], 'standard_andriod',
            now()->addDay(), $products, $statuses];
        $this->assertTrue((new ReflectionMethod(RevenueCatWebhookController::class, 'syncMembershipPurchase'))
            ->invokeArgs(new RevenueCatWebhookController(), $args));
        $this->assertSame(['tier_id' => 3, 'active' => true], $eventState);
    }
}
