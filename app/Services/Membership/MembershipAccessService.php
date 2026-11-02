<?php

namespace App\Services\Membership;

use App\Models\Hobby;
use App\Models\User;
use App\Models\Users;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MembershipAccessService
{
    public const ANNOUNCEMENT_VERSION = 'standard-premium-v1';

    public function snapshot(User|Users $user): array
    {
        $row = DB::table('account_access')->where('user_id', $user->id)->first();
        $legacySubscription = $user->subscription;
        $trial = DB::table('membership_trials')->where('user_id', $user->id)->first();
        $now = now()->utc();

        $legacyPackageHint = (int) ($legacySubscription->tier_id ?? 0) === 2 ? 'standard' : ((int) ($legacySubscription->tier_id ?? 0) === 3 ? 'premium' : null);
        $legacyChoice = $legacySubscription?->product_id
            ? app(MembershipProductCatalog::class)->resolve([$legacySubscription->product_id], null, $legacyPackageHint)
            : null;
        $legacyExpiryValid = $legacySubscription?->expires_at
            && Carbon::parse($legacySubscription->expires_at)->greaterThan($now);
        $legacyEntitlementValid = $legacyChoice
            ? ($legacyChoice['path'] === 'lifetime' || $legacyExpiryValid)
            : (bool) $legacyExpiryValid;
        $legacyPaid = ! $row && $legacySubscription?->active && in_array((int) $legacySubscription->tier_id, [2, 3], true)
            && strtolower((string) ($legacySubscription->environment ?? config('membership.store_environment', 'production'))) === strtolower((string) config('membership.store_environment', 'production'))
            && $legacyEntitlementValid;
        $legacyPackage = $legacyPaid ? $legacyPackageHint : null;
        $status = $row->access_status ?? ($legacyPaid ? 'active' : 'none');
        $selected = $row->selected_package ?? $legacyPackage;
        $effective = $row->effective_package ?? $legacyPackage;
        $legacyPath = $legacyPaid ? ($legacyChoice['path'] ?? null) : null;
        $path = $row->payment_path ?? $legacyPath;
        $source = $row->access_source ?? ($legacyPaid ? 'legacy' : null);
        $trialEnds = $row->trial_ends_at ?? null;
        $accessEnds = $row->access_ends_at ?? ($legacyPaid ? $legacySubscription->expires_at : null);
        $expiration = app(MembershipExpirationPolicy::class)->apply($status, $path, $effective, $trialEnds, $accessEnds, $now);
        $status = $expiration['status'];
        if ($expiration['expired']) {
            $effective = null;
            if ($row && in_array($row->access_status, ['active', 'trial', 'billing_restricted'], true)) {
                DB::table('account_access')->where('id', $row->id)->where('updated_at', $row->updated_at)->update([
                    'access_status' => 'expired',
                    'effective_package' => null,
                    'updated_at' => $now,
                ]);
            }
        }

        $legacyAll = (bool) ($row->legacy_all_categories ?? false);
        $categoryIds = $this->selectedCategoryIds($user);

        $limits = $effective === 'premium'
            ? ['daily_challenges' => 5, 'category_count' => null, 'blocked_apps' => null, 'scheduled_blocking_hours' => 24, 'blocking_intervals' => null, 'blocked_app_usage_minutes' => 30, 'hardcore' => true, 'team_connections' => 10, 'full_research' => true]
            : ($effective === 'standard'
                ? ['daily_challenges' => 3, 'category_count' => $legacyAll ? null : 5, 'blocked_apps' => 10, 'scheduled_blocking_hours' => 12, 'blocking_intervals' => 8, 'blocked_app_usage_minutes' => 15, 'hardcore' => false, 'team_connections' => 5, 'full_research' => false]
                : ['daily_challenges' => 3, 'category_count' => 5, 'blocked_apps' => 10, 'scheduled_blocking_hours' => 12, 'blocking_intervals' => 8, 'blocked_app_usage_minutes' => 15, 'hardcore' => false, 'team_connections' => 5, 'full_research' => false]);

        $ack = DB::table('membership_announcement_acknowledgements')
            ->where('user_id', $user->id)->where('version', self::ANNOUNCEMENT_VERSION)->exists();
        $eligibleFree = $status === 'none' && (bool) ($row->legacy_announcement_eligible ?? false);

        return [
            'policy_version' => 1,
            'server_time' => $now->toISOString(),
            'selected_package' => $selected,
            'effective_package' => $effective,
            'payment_path' => $path,
            'access_status' => $status,
            'access_source' => $source,
            'product_id' => $row->product_id ?? ($legacyPaid ? $legacySubscription->product_id : null),
            'trial_ends_at' => $trialEnds ? Carbon::parse($trialEnds)->utc()->toISOString() : null,
            'access_ends_at' => $accessEnds ? Carbon::parse($accessEnds)->utc()->toISOString() : null,
            'trial_eligible' => ! $trial || $trial->status === 'eligible',
            'trial_checkout_pending' => $trial?->status === 'reserved',
            'pending_trial_checkout' => app(MembershipTrialReservation::class)->forUser($user->id, $trial),
            'legacy_benefits' => ['all_categories' => $legacyAll],
            'selected_category_ids' => $categoryIds,
            'limits' => $limits,
            'announcement' => ['version' => self::ANNOUNCEMENT_VERSION, 'required' => $eligibleFree && ! $ack],
        ];
    }

    /** Reuse onboarding choices when no separate membership selection exists. */
    public function selectedCategoryIds(User|Users $user): array
    {
        $selected = DB::table('membership_categories')->where('user_id', $user->id)
            ->orderBy('hobby_id')->pluck('hobby_id')->map(fn ($id) => (string) $id)->all();
        if ($selected !== []) return $selected;

        // Standard includes five categories. Keep Micro-Movement and choose the
        // remaining categories deterministically from the user's onboarding choices.
        return $user->hobbies()->orderByRaw("CASE WHEN LOWER(name) = 'micro-movement' THEN 0 ELSE 1 END")
            ->orderBy('hobbies.id')->limit(5)->pluck('hobbies.id')
            ->map(fn ($id) => (string) $id)->all();
    }

    public function categories(User|Users $user): array
    {
        $mandatoryId = Hobby::query()->whereRaw('LOWER(name) = ?', ['micro-movement'])->value('id');

        return [
            'policy_version' => 1,
            'can_update' => true,
            'categories' => Hobby::query()->orderBy('id')->get(['id', 'name'])->map(fn (Hobby $hobby) => [
                'id' => (string) $hobby->id,
                'name' => $hobby->name,
                'mandatory' => $mandatoryId !== null && (int) $mandatoryId === (int) $hobby->id,
            ])->all(),
        ];
    }
}
