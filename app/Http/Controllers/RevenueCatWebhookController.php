<?php

namespace App\Http\Controllers;

use App\Http\Requests\RevenueCatWebhookRequest;
use App\Models\Subscription;
use App\Models\Tier;
use App\Models\Users;
use App\Models\SubscriptionLogs;
use App\Services\Membership\MembershipAttemptStatus;
use App\Services\Membership\VerifiedMembershipProductResolver;
use App\Services\Membership\MembershipWebhookPolicy;
use App\Services\Membership\MembershipReconciliationService;
use App\Services\Membership\CompletedMembershipTrial;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RevenueCatWebhookController extends Controller
{
    private const EVENT_INITIAL_PURCHASE = 'INITIAL_PURCHASE';
    private const EVENT_RENEWAL = 'RENEWAL';
    private const EVENT_CANCELLATION = 'CANCELLATION';
    private const EVENT_EXPIRATION = 'EXPIRATION';
    private const EVENT_PRODUCT_CHANGE = 'PRODUCT_CHANGE';
    private const EVENT_BILLING_ISSUE = 'BILLING_ISSUE';
    private const EVENT_NON_RENEWING_PURCHASE = 'NON_RENEWING_PURCHASE';

    public function __invoke(
        RevenueCatWebhookRequest $request,
        VerifiedMembershipProductResolver $products,
        MembershipAttemptStatus $attemptStatus,
        MembershipReconciliationService $reconciliation,
    ): JsonResponse
    {
        if (! $this->isAuthorized($request->header('Authorization'))) {
            return response()->json([
                'message' => 'Unauthorized webhook request.',
            ], 401);
        }

        $payload = $request->validated();
        if ($payload['type'] === 'TEST') {
            return response()->json(['message' => 'Webhook authentication verified; no access changed.']);
        }
        // These events cannot safely be interpreted as a direct product grant.
        if (strtolower((string) ($payload['store'] ?? '')) === 'promotional'
            || in_array($payload['type'], ['TRANSFER', 'REFUND_REVERSED', 'BILLING_ISSUE'], true)) {
            return $this->reconcileLifecycleEvent($payload, $reconciliation);
        }
        $user = $this->resolveUser($payload);

        if (! $user) {
            return response()->json([
                'message' => 'Unable to resolve user from RevenueCat ids. Send your numeric user id as app_user_id, or ensure this RevenueCat user id is already linked to a subscription.',
            ], 422);
        }

        if (strtolower((string) ($payload['environment'] ?? 'production')) !== app(\App\Services\Membership\MembershipEnvironment::class)->forUser($user)) {
            return response()->json(['message' => 'Purchase environment is isolated from this backend.'], 200);
        }

        $eventId = (string) $payload['id'];
        $inserted = DB::table('revenuecat_webhook_events')->insertOrIgnore([
            'event_id' => $eventId,
            'status' => 'received',
            'payload' => json_encode($payload),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $priorEvent = $inserted ? null : DB::table('revenuecat_webhook_events')->where('event_id', $eventId)->first();
        if ($priorEvent?->status === 'processed') {
            return response()->json(['message' => 'RevenueCat event already received.'], 200);
        }

        return DB::transaction(function () use ($user, $payload, $eventId, $products, $attemptStatus) {
            Users::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $event = DB::table('revenuecat_webhook_events')->where('event_id', $eventId)->lockForUpdate()->first();
            if ($event?->status === 'processed') {
                return response()->json(['message' => 'RevenueCat event already received.'], 200);
            }

            $subscription = Subscription::query()->where('user_id', $user->id)->lockForUpdate()->first();
            $eventType = $payload['type'] ?? null;
            $productId = $payload['product_id'] ?? null;
            $newProductId = $payload['new_product_id'] ?? null;
            $entitlementId = $this->resolvePrimaryEntitlementId($payload['entitlement_ids'] ?? []);
            $incomingTierId = $this->resolveTierId($entitlementId, $subscription?->tier_id);
            $freeTierId = $this->resolveFreeTierId($subscription?->tier_id ?? 1);
            $purchasedAt = $this->fromMilliseconds($payload['purchased_at_ms'] ?? null);
            $expiresAt = $this->fromMilliseconds($payload['expiration_at_ms'] ?? null);
            $eventAt = $this->fromMilliseconds($payload['event_timestamp_ms'] ?? null);

            if ($subscription?->revenuecat_event_at && $eventAt
                && strtolower((string) $subscription->environment) === strtolower((string) ($payload['environment'] ?? 'production'))
                && $eventAt->lessThanOrEqualTo($subscription->revenuecat_event_at)) {
                DB::table('revenuecat_webhook_events')->where('event_id', $eventId)->update(['status' => 'processed', 'processed_at' => now(), 'updated_at' => now()]);
                return response()->json(['message' => 'Older RevenueCat event ignored.', 'subscription_id' => $subscription->id], 200);
            }

            $eventState = $this->resolveEventState($eventType, $incomingTierId, $freeTierId, $expiresAt, $subscription?->expires_at, $subscription?->active);
            $preserveLegacySubscription = $this->syncMembershipPurchase(
                $user, $payload, $eventState, $eventType, $productId, $newProductId, $entitlementId, $expiresAt,
                $products, $attemptStatus,
            );
            $eventProductId = $eventType === self::EVENT_PRODUCT_CHANGE
                ? ($newProductId ?? $productId ?? $entitlementId)
                : ($productId ?? $entitlementId);

            if (! $preserveLegacySubscription) {
                $subscription = Subscription::query()->updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'tier_id' => $eventState['tier_id'],
                        'type' => $eventType,
                        'environment' => $payload['environment'] ?? null,
                        'active' => $eventState['active'],
                        'store' => $payload['store'] ?? null,
                        'product_id' => $eventProductId,
                        'revenuecat_user_id' => $payload['app_user_id'],
                        'started_at' => $purchasedAt ?? $subscription?->started_at,
                        'expires_at' => $expiresAt ?? $subscription?->expires_at,
                        'revenuecat_event_at' => $eventAt ?? $subscription?->revenuecat_event_at,
                    ]
                );
            }

            SubscriptionLogs::query()->create([
                'user_id' => $user->id,
                'tier_id' => $eventState['tier_id'],
                'type' => $eventType,
                'environment' => $payload['environment'] ?? null,
                'active' => $eventState['active'],
                'store' => $payload['store'] ?? null,
                'product_id' => $eventProductId,
                'revenuecat_user_id' => $payload['app_user_id'],
                'started_at' => $purchasedAt ?? $subscription?->started_at,
                'expires_at' => $expiresAt ?? $subscription?->expires_at,
            ]);

            DB::table('revenuecat_webhook_events')->where('event_id', $eventId)->update([
                'status' => 'processed', 'processed_at' => now(), 'updated_at' => now(),
            ]);

            return response()->json([
                'message' => 'Subscription synced successfully.',
                'subscription_id' => $subscription?->id,
            ], 200);
        });
    }

    private function syncMembershipPurchase(
        Users $user,
        array $payload,
        array &$eventState,
        ?string $eventType,
        ?string $productId,
        ?string $newProductId,
        ?string $entitlementId,
        ?Carbon $expiresAt,
        VerifiedMembershipProductResolver $products,
        MembershipAttemptStatus $attemptStatus,
    ): bool
    {
        if (! in_array($eventType, [
            self::EVENT_INITIAL_PURCHASE, self::EVENT_RENEWAL, self::EVENT_CANCELLATION,
            self::EVENT_EXPIRATION, self::EVENT_PRODUCT_CHANGE, self::EVENT_BILLING_ISSUE,
            self::EVENT_NON_RENEWING_PURCHASE, 'REFUND',
            'UNCANCELLATION', 'SUBSCRIPTION_EXTENDED', 'SUBSCRIPTION_PAUSED',
        ], true)) return true;

        $configuredEnvironment = app(\App\Services\Membership\MembershipEnvironment::class)->forUser($user);
        $environment = strtolower((string) ($payload['environment'] ?? 'production'));
        if ($environment !== $configuredEnvironment) return true;
        $resolvedProduct = $eventType === self::EVENT_PRODUCT_CHANGE ? ($newProductId ?? $productId) : $productId;
        $store = strtolower((string) ($payload['store'] ?? 'unknown'));
        $transactionId = $payload['transaction_id'] ?? null;
        if (! $resolvedProduct) return true;
        $basePlan = isset($payload['base_plan_id']) && is_string($payload['base_plan_id']) ? $payload['base_plan_id'] : null;
        $packageHint = match (strtolower((string) $entitlementId)) {
            'standard', 'standard_andriod', 'tier2', 'tier_2', 'tier2_android', 'tier_2_android' => 'standard',
            'premium', 'premium_android', 'premium android', 'tier3', 'tier_3', 'tier3_android', 'tier_3_android' => 'premium',
            default => null,
        };
        $choice = $products->resolve(
            (int) $user->id,
            [$resolvedProduct],
            $packageHint,
            $basePlan,
            $environment,
            array_filter([$transactionId, $payload['original_transaction_id'] ?? null]),
        );
        if (! $choice) {
            $products->logUnresolved([
                'user_id' => $user->id,
                'source' => 'webhook',
                'event_type' => $eventType,
                'product_id' => $resolvedProduct,
                'base_plan_id' => $basePlan,
                'environment' => $environment,
            ]);
            return true;
        }
        $package = $choice['package'];
        $path = $choice['path'];
        $basePlan = $choice['base_plan_id'];
        $storeProduct = $choice['store_product_id'];
        $attempt = $this->matchingAttempt($user, $choice, ['purchase', 'change']);
        if ($eventType === self::EVENT_PRODUCT_CHANGE) {
            if (! $attempt) return true;
            $quote = json_decode($attempt->quote, true) ?: [];
            if (($quote['replacement_mode'] ?? null) === 'DEFERRED') {
                if ($attempt->status !== 'scheduled') {
                    $attemptStatus->transition((int) $attempt->id, 'scheduled', null, 'verified_revenuecat_deferred_product_change');
                }
                return true;
            }
        }
        $revoked = MembershipWebhookPolicy::revoked($eventType, $path, $expiresAt);
        if ($revoked && ! $transactionId) {
            abort(422, 'A store transaction is required to revoke a purchase.');
        }

        if ($transactionId && $resolvedProduct) {
            DB::table('verified_purchases')->updateOrInsert(
                ['store' => $store, 'environment' => $environment, 'transaction_id' => $transactionId],
                [
                    'user_id' => $user->id,
                    'store' => $store,
                    'environment' => $environment,
                    'original_transaction_id' => $payload['original_transaction_id'] ?? null,
                    'product_id' => $storeProduct,
                    'base_plan_id' => $basePlan,
                    'entitlement' => $entitlementId,
                    'package' => $package,
                    'payment_path' => $path,
                    'renewal_status' => MembershipWebhookPolicy::renewalStatus($payload),
                    'expires_at' => $expiresAt,
                    'revoked_at' => $revoked ? now() : null,
                    'payload' => json_encode($payload),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        if ($transactionId && $revoked) {
            $accountTrial = DB::table('account_access')->where('user_id', $user->id)
                ->where('access_source', 'account_trial')->where('access_status', 'trial')
                ->where('access_ends_at', '>', now())->first();
            $replacement = DB::table('verified_purchases')->where('user_id', $user->id)
                ->where('environment', $environment)
                ->whereNull('revoked_at')->where(function ($query) {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })->orderByRaw("CASE package WHEN 'premium' THEN 2 WHEN 'standard' THEN 1 ELSE 0 END DESC")
                ->first();
            if ($accountTrial) {
                return true;
            } elseif (! $replacement) {
                $eventState = ['tier_id' => $this->resolveFreeTierId(1), 'active' => false];
                DB::table('account_access')->where('user_id', $user->id)->update([
                    'effective_package' => null, 'access_status' => 'expired', 'access_ends_at' => $expiresAt,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('account_access')->updateOrInsert(['user_id' => $user->id], [
                    'selected_package' => $replacement->package,
                    'effective_package' => $replacement->package,
                    'payment_path' => $replacement->payment_path,
                    'access_status' => 'active',
                    'access_source' => 'store',
                    'store_environment' => $environment,
                    'product_id' => $replacement->payment_path === 'lifetime' ? $replacement->product_id : $replacement->product_id . ':' . $replacement->base_plan_id,
                    'trial_ends_at' => null,
                    'access_ends_at' => $replacement->expires_at,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]);
                // The refunded/expired product must not overwrite another valid subscription.
                return true;
            }
            return false;
        }

        $periodType = strtoupper((string) ($payload['period_type'] ?? ''));
        $isTrial = $periodType === 'TRIAL';
        $selectedPackage = $package;
        $effectivePackage = $package;
        if (in_array($eventType, [self::EVENT_INITIAL_PURCHASE, self::EVENT_RENEWAL, self::EVENT_PRODUCT_CHANGE], true)) {
            if ($attempt && $transactionId) {
                $attemptStatus->transition((int) $attempt->id, 'completed', (string) $transactionId, 'verified_revenuecat_webhook');
            }
        }
        if ($isTrial) {
            $attempt = $this->matchingAttempt($user, $choice, ['trial'])
                ?? (new CompletedMembershipTrial())->find($user->id, $choice,
                    [$transactionId, $payload['original_transaction_id'] ?? null]);
            if ($attempt) {
                $quoted = json_decode($attempt->quote, true) ?: [];
                $selectedPackage = $quoted['selected_package'] ?? $package;
                $effectivePackage = $quoted['trial_access_package'] ?? $package;
                if ($transactionId && $attempt->status !== 'completed') {
                    $attemptStatus->transition((int) $attempt->id, 'completed', (string) $transactionId, 'verified_revenuecat_trial');
                }
            }
            DB::table('membership_trials')->updateOrInsert(['user_id' => $user->id], [
                'status' => 'consumed', 'started_at' => now(), 'ends_at' => $expiresAt,
                'source' => 'store', 'transaction_id' => $transactionId, 'updated_at' => now(), 'created_at' => now(),
            ]);
        }

        $isActive = $eventState['active']
            && ($path === 'lifetime' || ($expiresAt && $expiresAt->isFuture()));
        if (! $isActive) $effectivePackage = null;
        DB::table('account_access')->updateOrInsert(['user_id' => $user->id], [
            'selected_package' => $selectedPackage,
            'effective_package' => $effectivePackage,
            'payment_path' => $path,
            'access_status' => $isTrial && $isActive ? 'trial' : ($isActive ? 'active' : 'expired'),
            'access_source' => 'store',
            'store_environment' => $environment,
            'product_id' => $choice['product_id'],
            'trial_ends_at' => $isTrial && $isActive ? $expiresAt : null,
            'access_ends_at' => $expiresAt,
            'updated_at' => now(),
            'created_at' => now(),
        ]);
        return false;
    }

    private function matchingAttempt(Users $user, array $choice, array $operations): ?object
    {
        $matches = [];
        foreach (DB::table('membership_attempts')->where('user_id', $user->id)
            ->whereIn('operation', $operations)->whereIn('status', ['pending', 'scheduled', 'cancelled'])
            ->latest('created_at')->get() as $attempt) {
            $quote = json_decode($attempt->quote, true) ?: [];
            if (($quote['product_id'] ?? null) === $choice['store_product_id']
                && ($quote['base_plan_id'] ?? null) === $choice['base_plan_id']) $matches[] = $attempt;
        }

        $current = array_values(array_filter($matches, fn ($attempt) => in_array($attempt->status, ['pending', 'scheduled'], true)));
        $candidates = $current !== [] ? $current : $matches;
        return count($candidates) === 1 ? $candidates[0] : null;
    }

    private function reconcileLifecycleEvent(array $payload, MembershipReconciliationService $reconciliation): JsonResponse
    {
        $users = [];
        if ($payload['type'] === 'TRANSFER') {
            foreach (array_merge($payload['transferred_from'], $payload['transferred_to']) as $id) {
                $user = $this->resolveUserFromCandidateId($id);
                if ($user) $users[$user->id] = $user;
            }
        } else {
            $user = $this->resolveUser($payload);
            if ($user) $users[$user->id] = $user;
        }
        if ($users === []) return response()->json(['message' => 'No linked membership account found.'], 422);

        // A retry reads current state again; it never replays an old grant or revocation.
        // Reconciliation scopes every read to this backend's store environment.
        try {
            foreach ($users as $user) {
                if (app(\App\Services\Membership\MembershipEnvironment::class)->accepts($user, $payload['environment'] ?? null)) {
                    $reconciliation->reconcile($user);
                }
            }
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => 'Membership verification unavailable. Retry this event.'], 503);
        }
        return response()->json(['message' => 'Current membership ownership reconciled.']);
    }

    private function isAuthorized(?string $authorizationHeader): bool
    {
        $secret = (string) config('services.revenuecat.webhook_secret', '');

        if ($secret === '') {
            return false;
        }

        if (! $authorizationHeader) {
            return false;
        }

        $expected = 'Bearer ' . $secret;

        return hash_equals($expected, trim($authorizationHeader));
    }

    private function resolveUser(array $payload): ?Users
    {
        $candidateIds = array_filter([
            $payload['app_user_id'] ?? null,
            $payload['original_app_user_id'] ?? null,
            ...Arr::wrap($payload['aliases'] ?? []),
        ], static fn($value) => is_string($value) && $value !== '');

        foreach ($candidateIds as $candidateId) {
            $user = $this->resolveUserFromCandidateId($candidateId);

            if ($user) {
                return $user;
            }
        }
        return null;
    }

    private function resolveUserFromCandidateId(string $candidateId): ?Users
    {
        if (ctype_digit($candidateId)) {
            return Users::query()->find((int) $candidateId);
        }

        $subscription = Subscription::query()
            ->where('revenuecat_user_id', $candidateId)
            ->first();

        if (! $subscription) {
            return null;
        }
        return Users::query()->find($subscription->user_id);
    }

    private function resolveTierId(?string $entitlementId, ?int $fallbackTierId): int
    {
        if (!$entitlementId) {
            return $fallbackTierId ?? 1;
        }

        if (in_array(strtolower($entitlementId), ['premium', 'premium_android', 'premium android', 'tier3', 'tier_3', 'tier3_android', 'tier_3_android'], true)) {
            return (int) (Tier::query()->where('name', '3')->value('id') ?? 3);
        }
        if (in_array(strtolower($entitlementId), ['standard', 'standard_andriod', 'tier2', 'tier_2', 'tier2_android', 'tier_2_android'], true)) {
            return (int) (Tier::query()->where('name', '2')->value('id') ?? 2);
        }
        if (preg_match('/tier_(\d+)/', $entitlementId, $matches)) {

            $tier = Tier::query()
                ->where('name', $matches[1])
                ->first();

            if ($tier) {
                return $tier->id;
            }
        }
        return $fallbackTierId ?? 1;
    }

    private function resolvePrimaryEntitlementId(mixed $entitlementIds): ?string
    {
        if (! is_array($entitlementIds) || $entitlementIds === []) {
            return null;
        }

        $fallback = null;
        foreach ($entitlementIds as $entitlementId) {
            if (! is_string($entitlementId) || $entitlementId === '') continue;
            $normalized = strtolower($entitlementId);
            if (in_array($normalized, ['premium', 'premium_android', 'premium android', 'tier3', 'tier_3', 'tier3_android', 'tier_3_android'], true)) return $entitlementId;
            if ($fallback === null && in_array($normalized, ['standard', 'standard_andriod', 'tier2', 'tier_2', 'tier2_android', 'tier_2_android'], true)) $fallback = $entitlementId;
        }

        return $fallback;
    }

    private function fromMilliseconds(int|float|string|null $milliseconds): ?Carbon
    {
        if ($milliseconds === null || $milliseconds === '') {
            return null;
        }

        return Carbon::createFromTimestampMs((float) $milliseconds)->utc();
    }

    private function resolveFreeTierId(int $fallbackTierId): int
    {
        $freeTierByName = Tier::query()->where('name', '1')->value('id');
        if ($freeTierByName) {
            return (int) $freeTierByName;
        }

        $freeTierById = Tier::query()->find(1);
        if ($freeTierById) {
            return (int) $freeTierById->id;
        }

        return $fallbackTierId;
    }

    private function resolveEventState(
        ?string $eventType,
        int $incomingTierId,
        int $freeTierId,
        ?Carbon $incomingExpiresAt,
        ?Carbon $currentExpiresAt,
        ?bool $currentActive
    ): array {
        $effectiveExpiresAt = $incomingExpiresAt ?? $currentExpiresAt;
        $isExpired = $effectiveExpiresAt ? $effectiveExpiresAt->isPast() : false;

        if ($eventType === 'REFUND') {
            return [
                'tier_id' => $freeTierId,
                'active' => false,
            ];
        }

        if ($eventType === self::EVENT_EXPIRATION) {

            if ($incomingExpiresAt && $incomingExpiresAt->isFuture()) {
                return [
                    'tier_id' => $incomingTierId,
                    'active' => true,
                ];
            }

            return [
                'tier_id' => $freeTierId,
                'active' => false,
            ];
        }

        if (in_array($eventType, [self::EVENT_CANCELLATION, 'UNCANCELLATION', 'SUBSCRIPTION_EXTENDED', 'SUBSCRIPTION_PAUSED'], true)) {
            return [
                'tier_id' => $incomingTierId,
                'active' => ! $isExpired,
            ];
        }

        if ($eventType === self::EVENT_BILLING_ISSUE) {
            return [
                'tier_id' => $incomingTierId,
                'active' => ! $isExpired,
            ];
        }

        if (in_array($eventType, [self::EVENT_INITIAL_PURCHASE, self::EVENT_RENEWAL, self::EVENT_PRODUCT_CHANGE], true)) {
            return [
                'tier_id' => $incomingTierId,
                'active' => true,
            ];
        }

        if ($eventType === self::EVENT_NON_RENEWING_PURCHASE) {
            return [
                'tier_id' => $incomingTierId,
                'active' => ! $isExpired,
            ];
        }

        return [
            'tier_id' => $incomingTierId,
            'active' => $currentActive ?? false,
        ];
    }
}
