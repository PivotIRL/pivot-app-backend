<?php

namespace Tests\Unit;

use App\Services\Membership\MembershipProductCatalog;
use PHPUnit\Framework\TestCase;

class MembershipProductCatalogTest extends TestCase
{
    public function test_trial_quotes_use_the_exact_live_offer_ids(): void
    {
        $catalog = new MembershipProductCatalog();
        $this->assertSame('free-trial-standard-monthly', $catalog->trialOfferId('standard', 'monthly'));
        $this->assertSame('free-trial-annual-standard-v2', $catalog->trialOfferId('standard', 'annual'));
        $this->assertSame('free-trial-premium-monthly', $catalog->trialOfferId('premium', 'monthly'));
        $this->assertSame('free-trial-premium-annual-v2', $catalog->trialOfferId('premium', 'annual'));
        $this->assertNull($catalog->trialOfferId('standard', 'lifetime'));
        $this->expectException(\InvalidArgumentException::class);
        $catalog->trialOfferId('unknown', 'monthly');
    }

    public function test_it_resolves_recurring_products_only_with_an_exact_plan(): void
    {
        $catalog = new MembershipProductCatalog();

        $this->assertSame([
            'package' => 'standard',
            'path' => 'monthly',
            'product_id' => 'standard_android:monthly',
            'store_product_id' => 'standard_android',
            'base_plan_id' => 'monthly',
        ], $catalog->resolve(['standard_android'], 'monthly', 'standard'));

        $this->assertSame('annual', $catalog->resolve(['tier_2_annual:tier-2-annual'], null, 'standard')['path']);
        $this->assertNull($catalog->resolve(['standard_android'], null, 'standard'));
        $this->assertNull($catalog->resolve(['standard_android'], 'annual', 'premium'));
        $this->assertNull($catalog->resolve(['unconfigured_product'], 'monthly', 'standard'));
    }

    public function test_it_resolves_lifetime_products_without_a_recurring_plan(): void
    {
        $choice = (new MembershipProductCatalog())->resolve(['premium_lifetime_android'], null, 'premium');

        $this->assertSame('lifetime', $choice['path']);
        $this->assertNull($choice['base_plan_id']);
    }

    public function test_checkout_and_recognition_use_all_six_live_revenuecat_identities(): void
    {
        $catalog = new MembershipProductCatalog();
        $products = [
            ['standard', 'monthly', 'standard_android_monthly', 'standard-android-monthly'],
            ['standard', 'annual', 'standard_android_annual', 'standard-android-annual-v2'],
            ['standard', 'lifetime', 'standard_android_lifetime', null],
            ['premium', 'monthly', 'premium_android_monthly', 'premium-android-monthly'],
            ['premium', 'annual', 'premium_android_annual', 'premium-android-annual-v2'],
            ['premium', 'lifetime', 'premium_android_lifetime', null],
        ];

        foreach ($products as [$package, $path, $product, $basePlan]) {
            $combined = $basePlan === null ? $product : $product . ':' . $basePlan;
            $expected = ['package' => $package, 'path' => $path, 'product_id' => $combined,
                'store_product_id' => $product, 'base_plan_id' => $basePlan];
            $this->assertSame($expected, $catalog->checkoutProduct($package, $path));
            $this->assertSame($expected, $catalog->resolve([$product], $basePlan, $package));
            $this->assertSame($expected, $catalog->resolve([$combined], null, $package));
            if ($path === 'annual') $this->assertNull($catalog->resolve([$product], null, null));
            else $this->assertSame($expected, $catalog->resolve([$product], null, null));
            $this->assertNull($catalog->resolve([$product], 'wrong-plan', $package));
            $this->assertNull($catalog->resolve([$product], $basePlan, $package === 'standard' ? 'premium' : 'standard'));
        }
    }

    public function test_current_products_cannot_be_substituted_for_historical_or_ambiguous_products(): void
    {
        $catalog = new MembershipProductCatalog();
        $this->assertNull($catalog->resolve(['standard_android_monthly'], 'monthly', 'standard'));
        $this->assertNull($catalog->resolve(['standard_android_monthly:monthly'], null, 'standard'));
        $this->assertNull($catalog->resolve(['standard_android_monthly', 'standard_android_annual'], null, 'standard'));
        $this->assertSame('standard_android', $catalog->resolve(['standard_android:monthly'], null, 'standard')['store_product_id']);
        $this->assertSame('standard_android_monthly', $catalog->checkoutProduct('standard', 'monthly')['store_product_id']);
        $this->expectException(\InvalidArgumentException::class);
        $catalog->checkoutProduct('unknown', 'monthly');
    }

    public function test_annual_v2_checkout_preserves_old_purchases_without_guessing_a_base_plan(): void
    {
        $catalog = new MembershipProductCatalog();
        foreach (['standard', 'premium'] as $package) {
            $product = $package . '_android_annual';
            $oldBase = $package . '-android-annual';
            $this->assertSame($oldBase . '-v2', $catalog->checkoutProduct($package, 'annual')['base_plan_id']);
            $this->assertSame($oldBase, $catalog->resolve([$product . ':' . $oldBase], null, $package)['base_plan_id']);
            $this->assertNull($catalog->resolve([$product], null, $package));
            $this->assertNull($catalog->resolve([$product . ':' . $oldBase], $oldBase . '-v2', $package));
        }
    }
}
