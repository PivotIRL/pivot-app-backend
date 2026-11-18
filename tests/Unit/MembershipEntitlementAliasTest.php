<?php

namespace Tests\Unit;

use App\Http\Controllers\RevenueCatWebhookController;
use App\Services\Membership\MembershipReconciliationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class MembershipEntitlementAliasTest extends TestCase
{
    public function test_live_premium_entitlement_with_space_is_recognized(): void
    {
        $service = (new ReflectionClass(MembershipReconciliationService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'packageFromEntitlements');
        $this->assertSame('premium', $method->invoke($service, [
            ['state' => 'active', 'lookup_key' => 'Premium Android'],
        ]));
        $this->assertNull($method->invoke($service, [
            ['state' => 'inactive', 'lookup_key' => 'Premium Android'],
        ]));
        $controller = (new ReflectionClass(RevenueCatWebhookController::class))->newInstanceWithoutConstructor();
        $this->assertSame('Premium Android', (new ReflectionMethod($controller, 'resolvePrimaryEntitlementId'))
            ->invoke($controller, ['standard_andriod', 'Premium Android']));
    }

    public function test_reconciliation_recognizes_the_live_standard_alias_without_guessing_other_names(): void
    {
        $service = (new ReflectionClass(MembershipReconciliationService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'packageFromEntitlements');
        $this->assertSame('standard', $method->invoke($service, [
            ['state' => 'active', 'lookup_key' => 'standard_andriod'],
        ]));
        $this->assertNull($method->invoke($service, [
            ['state' => 'inactive', 'lookup_key' => 'standard_andriod'],
        ]));
        $this->assertNull($method->invoke($service, [
            ['state' => 'active', 'lookup_key' => 'unknown_standard'],
        ]));
        $this->assertSame('premium', $method->invoke($service, [
            ['state' => 'active', 'lookup_key' => 'standard_andriod'],
            ['state' => 'active', 'lookup_key' => 'premium'],
        ]));
    }

    public function test_webhook_preserves_the_known_alias_and_prefers_premium_when_both_exist(): void
    {
        $controller = (new ReflectionClass(RevenueCatWebhookController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($controller, 'resolvePrimaryEntitlementId');
        $this->assertSame('standard_andriod', $method->invoke($controller, ['standard_andriod']));
        $this->assertSame('premium', $method->invoke($controller, ['standard_andriod', 'premium']));
        $this->assertNull($method->invoke($controller, ['unknown_standard']));
    }

    public function test_reconciliation_recognizes_only_active_premium_android_entitlements(): void
    {
        $service = (new ReflectionClass(MembershipReconciliationService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'packageFromEntitlements');
        $this->assertSame('premium', $method->invoke($service, [
            ['state' => 'active', 'lookup_key' => 'standard_andriod'],
            ['state' => 'active', 'lookup_key' => 'premium_android'],
        ]));
        $this->assertNull($method->invoke($service, [
            ['state' => 'inactive', 'lookup_key' => 'premium_android'],
        ]));
        $this->assertNull($method->invoke($service, [
            ['state' => 'active', 'lookup_key' => 'unknown_premium'],
        ]));
    }

    public function test_webhook_selects_premium_android_over_standard(): void
    {
        $controller = (new ReflectionClass(RevenueCatWebhookController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($controller, 'resolvePrimaryEntitlementId');
        $this->assertSame('premium_android', $method->invoke($controller, ['standard_andriod', 'premium_android']));
        $this->assertSame('premium_android', $method->invoke($controller, ['premium_android', 'standard_andriod']));
    }
}
