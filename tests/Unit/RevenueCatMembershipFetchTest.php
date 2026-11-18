<?php

namespace Tests\Unit;

use App\Services\RevenueCatService;
use App\Services\Membership\MembershipProductCatalog;
use App\Services\Membership\MembershipReconciliationService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class RevenueCatMembershipFetchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.revenuecat.api_key' => 'test-api-key',
            'services.revenuecat.project_id' => 'test-project',
            'services.revenuecat.api_url' => 'https://api.revenuecat.test/v2',
        ]);
    }

    public function test_api_failure_is_not_reported_as_an_empty_purchase_collection(): void
    {
        Log::shouldReceive('warning')->once();
        Http::fake(fn () => Http::response(['error' => 'rate limited'], 429));

        $state = (new RevenueCatService())->fetchCustomerMembershipState('user-1', 'production');

        $this->assertFalse($state['ok']);
        $this->assertSame([], $state['subscriptions']);
        Http::assertSentCount(1);
    }

    public function test_complete_empty_collections_are_distinct_from_api_failure(): void
    {
        Http::fake(fn () => Http::response(['items' => [], 'next_page' => null]));

        $state = (new RevenueCatService())->fetchCustomerMembershipState('user-1', 'production');

        $this->assertTrue($state['ok']);
        $this->assertSame([], $state['subscriptions']);
        $this->assertSame([], $state['purchases']);
        Http::assertSentCount(2);
    }

    public function test_all_pages_are_collected_before_a_state_is_returned(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/subscriptions')) {
                return str_contains($url, 'starting_after=cursor-1')
                    ? Http::response(['items' => [['id' => 'sub-2']], 'next_page' => null])
                    : Http::response(['items' => [['id' => 'sub-1']], 'next_page' => 'https://api.revenuecat.test/v2?page=2&starting_after=cursor-1']);
            }

            return Http::response(['items' => [['id' => 'purchase-1']], 'next_page' => null]);
        });

        $state = (new RevenueCatService())->fetchCustomerMembershipState('user-1', 'production');

        $this->assertTrue($state['ok']);
        $this->assertCount(2, $state['subscriptions']);
        $this->assertCount(1, $state['purchases']);
        Http::assertSentCount(3);
    }

    public function test_v2_product_ids_are_resolved_to_exact_store_identities_for_both_collections(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/products/prod4704536aa8')) {
                return Http::response(['id' => 'prod4704536aa8',
                    'store_identifier' => 'standard_android_monthly:standard-android-monthly']);
            }
            if (str_contains($request->url(), '/products/prodLifetime')) {
                return Http::response(['id' => 'prodLifetime', 'store_identifier' => 'premium_android_lifetime']);
            }
            return Http::response(['items' => str_contains($request->url(), '/subscriptions')
                ? [['product_id' => 'prod4704536aa8'], ['product_id' => 'prod4704536aa8']]
                : [['product_id' => 'prodLifetime']], 'next_page' => null]);
        });

        $state = (new RevenueCatService())->fetchCustomerMembershipState('434', 'sandbox');

        $this->assertTrue($state['ok']);
        $service = (new \ReflectionClass(MembershipReconciliationService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($service, 'storeIdentifiers');
        $catalog = new MembershipProductCatalog();
        $choice = $catalog->resolve($method->invoke($service, $state['subscriptions'][0]), null, 'standard');
        $this->assertSame('standard_android_monthly', $choice['store_product_id']);
        $this->assertSame('standard-android-monthly', $choice['base_plan_id']);
        $this->assertSame('prod4704536aa8', $state['subscriptions'][0]['product_id']);
        $choice = $catalog->resolve($method->invoke($service, $state['purchases'][0]), null, 'premium');
        $this->assertSame('premium_android_lifetime', $choice['store_product_id']);
        Http::assertSentCount(4); // Each unique product is fetched once per reconciliation.
    }

    public function test_product_lookup_failure_does_not_become_successful_empty_access(): void
    {
        Log::shouldReceive('warning')->once();
        Http::fake(fn (Request $request) => str_contains($request->url(), '/products/')
            ? Http::response(['error' => 'forbidden'], 403)
            : Http::response(['items' => [['product_id' => 'prod4704536aa8']], 'next_page' => null]));

        $state = (new RevenueCatService())->fetchCustomerMembershipState('434', 'sandbox');

        $this->assertFalse($state['ok']);
        $this->assertNotEmpty($state['error']);
    }

    public function test_mismatched_product_metadata_is_rejected(): void
    {
        Log::shouldReceive('warning')->once();
        Http::fake(fn (Request $request) => str_contains($request->url(), '/products/')
            ? Http::response(['id' => 'prodOther', 'store_identifier' => 'premium_android_lifetime'])
            : Http::response(['items' => [['product_id' => 'prod4704536aa8']], 'next_page' => null]));

        $this->assertFalse((new RevenueCatService())->fetchCustomerMembershipState('434', 'sandbox')['ok']);
    }
}
