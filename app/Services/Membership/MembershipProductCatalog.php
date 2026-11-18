<?php

namespace App\Services\Membership;

class MembershipProductCatalog
{
    private const PRODUCTS = [
        // App Store products have distinct billing-period IDs and no Google base plan.
        'standard_lifetime' => ['standard', 'lifetime', 'standard_lifetime', null],
        'premium_lifetime' => ['premium', 'lifetime', 'premium_lifetime', null],
        'standard_monthly' => ['standard', 'monthly', 'standard_monthly', null],
        'standard_annual' => ['standard', 'annual', 'standard_annual', null],
        'premium_monthly' => ['premium', 'monthly', 'premium_monthly', null],
        'premium_annual' => ['premium', 'annual', 'premium_annual', null],
        'standard_android_annual:standard-android-annual-v2' => ['standard', 'annual', 'standard_android_annual', 'standard-android-annual-v2'],
        'premium_android_annual:premium-android-annual-v2' => ['premium', 'annual', 'premium_android_annual', 'premium-android-annual-v2'],
        'standard_android_monthly:standard-android-monthly' => ['standard', 'monthly', 'standard_android_monthly', 'standard-android-monthly'],
        'standard_android_annual:standard-android-annual' => ['standard', 'annual', 'standard_android_annual', 'standard-android-annual'],
        'standard_android_lifetime' => ['standard', 'lifetime', 'standard_android_lifetime'],
        'premium_android_monthly:premium-android-monthly' => ['premium', 'monthly', 'premium_android_monthly', 'premium-android-monthly'],
        'premium_android_annual:premium-android-annual' => ['premium', 'annual', 'premium_android_annual', 'premium-android-annual'],
        'premium_android_lifetime' => ['premium', 'lifetime', 'premium_android_lifetime'],
        // Historical identities remain valid for existing purchases, not new checkout quotes.
        'standard_android:monthly' => ['standard', 'monthly', 'standard_android'],
        'standard_android:annual' => ['standard', 'annual', 'standard_android'],
        'premium_android:monthly' => ['premium', 'monthly', 'premium_android'],
        'premium_android:annual' => ['premium', 'annual', 'premium_android'],
        'standard_lifetime_android' => ['standard', 'lifetime', 'standard_lifetime_android'],
        'premium_lifetime_android' => ['premium', 'lifetime', 'premium_lifetime_android'],
        'tier_2:tier2' => ['standard', 'monthly', 'tier_2'],
        'tier_2:monthly' => ['standard', 'monthly', 'tier_2'],
        'tier_2_ios:monthly' => ['standard', 'monthly', 'tier_2_ios'],
        'tier_2_ios:annual' => ['standard', 'annual', 'tier_2_ios'],
        'tier_2_annual' => ['standard', 'annual', 'tier_2_annual'],
        'tier_2_annual:tier-2-annual' => ['standard', 'annual', 'tier_2_annual'],
        'tier_2:tier-2-annual' => ['standard', 'annual', 'tier_2'],
        'tier_2_lifetime' => ['standard', 'lifetime', 'tier_2_lifetime'],
        'tier_3:tier3' => ['premium', 'monthly', 'tier_3'],
        'tier_3:monthly' => ['premium', 'monthly', 'tier_3'],
        'tier_3_ios:monthly' => ['premium', 'monthly', 'tier_3_ios'],
        'tier_3_ios:annual' => ['premium', 'annual', 'tier_3_ios'],
        'tier_3_annual' => ['premium', 'annual', 'tier_3_annual'],
        'tier_3_annual:tier-3-annual' => ['premium', 'annual', 'tier_3_annual'],
        'tier_3:tier-3-annual' => ['premium', 'annual', 'tier_3'],
        'tier_3_lifetime' => ['premium', 'lifetime', 'tier_3_lifetime'],
    ];

    public function checkoutProduct(string $package, string $path): array
    {
        if (! in_array($package, ['standard', 'premium'], true)
            || ! in_array($path, ['monthly', 'annual', 'lifetime'], true)) {
            throw new \InvalidArgumentException('Unknown membership choice.');
        }

        return $this->resolve(
            [$package . '_android_' . $path],
            $path === 'lifetime' ? null : $package . '-android-' . $path . ($path === 'annual' ? '-v2' : ''),
            $package,
        );
    }

    public function trialOfferId(string $package, string $path): ?string
    {
        $this->checkoutProduct($package, $path); // Validate the exact supported choice.
        return match ($package . ':' . $path) {
            'standard:monthly' => 'free-trial-standard-monthly',
            'standard:annual' => 'free-trial-annual-standard-v2',
            'premium:monthly' => 'free-trial-premium-monthly',
            'premium:annual' => 'free-trial-premium-annual-v2',
            default => null,
        };
    }

    public function resolve(array $identifiers, ?string $basePlan, ?string $packageHint): ?array
    {
        $matches = [];

        foreach ($identifiers as $identifier) {
            if (! is_string($identifier) || $identifier === '') continue;
            $identifier = strtolower(trim($identifier));

            foreach (self::PRODUCTS as $catalogId => $definition) {
                [$package, $path, $storeProduct, $configuredBasePlan] = array_pad($definition, 4, null);
                if ($identifier !== $catalogId && $identifier !== $storeProduct) continue;
                if ($basePlan !== null && $basePlan !== '' && ($configuredBasePlan !== null
                    ? strtolower($basePlan) !== $configuredBasePlan
                    : ! $this->basePlanMatches($path, $storeProduct, $basePlan))) continue;
                if ($packageHint !== null && $packageHint !== $package) continue;
                $resolvedBasePlan = $path === 'lifetime' ? null
                    : (array_key_exists(3, $definition) ? $configuredBasePlan : $path);

                $matches[$package . '|' . $path . '|' . $storeProduct . '|' . ($configuredBasePlan ?? '')] = [
                    'package' => $package,
                    'path' => $path,
                    'product_id' => $resolvedBasePlan === null ? $storeProduct : $storeProduct . ':' . $resolvedBasePlan,
                    'store_product_id' => $storeProduct,
                    'base_plan_id' => $resolvedBasePlan,
                ];
            }
        }

        return count($matches) === 1 ? array_values($matches)[0] : null;
    }

    private function basePlanMatches(string $path, string $storeProduct, string $basePlan): bool
    {
        if ($path === 'lifetime') return strtolower($basePlan) === 'lifetime';

        $allowedPlans = match ($storeProduct) {
            'standard_android', 'premium_android', 'tier_2_ios', 'tier_3_ios' => $path === 'monthly' ? ['monthly'] : ['annual'],
            'tier_2' => $path === 'monthly' ? ['monthly', 'tier2'] : ['annual', 'tier-2-annual'],
            'tier_3' => $path === 'monthly' ? ['monthly', 'tier3'] : ['annual', 'tier-3-annual'],
            'tier_2_annual' => ['annual', 'tier-2-annual'],
            'tier_3_annual' => ['annual', 'tier-3-annual'],
            default => [],
        };

        return in_array(strtolower($basePlan), $allowedPlans, true);
    }
}
