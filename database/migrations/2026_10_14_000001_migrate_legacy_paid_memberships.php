<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $storeEnvironment = strtolower((string) config('membership.store_environment', 'production'));
        $tierIds = DB::table('tier')->whereIn('name', ['2', '3'])->pluck('id', 'name');
        $standardTierId = (int) ($tierIds['2'] ?? 2);
        $premiumTierId = (int) ($tierIds['3'] ?? 3);
        $legacyPaid = DB::table('subscriptions')
            ->whereIn('tier_id', [$standardTierId, $premiumTierId])
            ->whereNotNull('product_id')
            ->whereRaw('LOWER(COALESCE(environment, ?)) = ?', [$storeEnvironment, $storeEnvironment])
            ->get(['user_id', 'tier_id', 'active', 'product_id', 'expires_at']);

        foreach ($legacyPaid as $subscription) {
            $product = strtolower((string) $subscription->product_id);
            $package = (int) $subscription->tier_id === $standardTierId ? 'standard' : 'premium';
            [$mappedPackage, $path, $productId] = match ($product) {
                'standard_android:monthly' => ['standard', 'monthly', 'standard_android:monthly'],
                'standard_android:annual' => ['standard', 'annual', 'standard_android:annual'],
                'premium_android:monthly' => ['premium', 'monthly', 'premium_android:monthly'],
                'premium_android:annual' => ['premium', 'annual', 'premium_android:annual'],
                'standard_lifetime_android' => ['standard', 'lifetime', 'standard_lifetime_android'],
                'premium_lifetime_android' => ['premium', 'lifetime', 'premium_lifetime_android'],
                'tier_2:tier2' => ['standard', 'monthly', 'tier_2:tier2'],
                'tier_2:tier-2-annual', 'tier_2_annual:tier-2-annual', 'tier_2_annual' => ['standard', 'annual', $subscription->product_id],
                'tier_2_lifetime' => ['standard', 'lifetime', 'tier_2_lifetime'],
                'tier_3:tier3' => ['premium', 'monthly', 'tier_3:tier3'],
                'tier_3:tier-3-annual', 'tier_3_annual:tier-3-annual', 'tier_3_annual' => ['premium', 'annual', $subscription->product_id],
                'tier_3_lifetime' => ['premium', 'lifetime', 'tier_3_lifetime'],
                default => [$package, null, $subscription->product_id],
            };
            if ($mappedPackage !== $package) continue;
            $active = (bool) $subscription->active && (
                $path === 'lifetime'
                    ? (! $subscription->expires_at || Carbon::parse($subscription->expires_at)->isFuture())
                    : ($subscription->expires_at && Carbon::parse($subscription->expires_at)->isFuture())
            );

            DB::table('account_access')->insertOrIgnore([
                'user_id' => $subscription->user_id,
                'selected_package' => $package,
                'effective_package' => $active ? $package : null,
                'payment_path' => $path,
                'access_status' => $active ? 'active' : 'expired',
                'access_source' => 'legacy',
                'product_id' => $productId,
                'base_plan_id' => $path && $path !== 'lifetime' ? $path : null,
                'access_ends_at' => $subscription->expires_at,
                'legacy_all_categories' => (int) $subscription->tier_id === $standardTierId,
                'policy_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
    }
};
