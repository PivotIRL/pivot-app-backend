<?php

namespace App\Services\Membership;

use App\Models\User;
use App\Models\Users;
use App\Services\RevenueCatService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MembershipReconciliationService
{
    public function __construct(
        private readonly RevenueCatService $revenueCat,
        private readonly VerifiedMembershipProductResolver $products,
        private readonly MembershipAttemptStatus $attemptStatus,
        private readonly MembershipProductCatalog $catalog,
    ) {}

    public function reconcile(User|Users $user): void
    {
        $environment = strtolower((string) config('membership.store_environment', 'production'));
        $customerId = (string) ($user->revenuecat_user_id ?: $user->id);
        $subscriptionUpdatedAt = DB::table('subscriptions')->where('user_id', $user->id)->value('updated_at');
        $accessUpdatedAt = DB::table('account_access')->where('user_id', $user->id)->value('updated_at');
        $state = $this->revenueCat->fetchCustomerMembershipState($customerId, $environment);
        if (! $state['ok']) throw new RuntimeException($state['error'] ?? 'RevenueCat verification failed.');

        foreach (['subscriptions', 'purchases'] as $collection) {
            foreach ($state[$collection] as $item) {
                if (! is_array($item)) {
                    throw new RuntimeException('RevenueCat returned an invalid membership record.');
                }
            }
        }

        DB::transaction(function () use ($user, $state, $environment, $subscriptionUpdatedAt, $accessUpdatedAt) {
            Users::query()->lockForUpdate()->findOrFail($user->id);
            $currentSubscriptionUpdatedAt = DB::table('subscriptions')->where('user_id', $user->id)->value('updated_at');
            $currentAccessUpdatedAt = DB::table('account_access')->where('user_id', $user->id)->value('updated_at');
            if ($currentSubscriptionUpdatedAt !== $subscriptionUpdatedAt || $currentAccessUpdatedAt !== $accessUpdatedAt) {
                throw new RuntimeException('Membership changed during reconciliation. Retry to verify the latest purchase state.');
            }
            $this->applyState($user, $state, $environment);
            // Do not release a trial while an unrecognized subscription still needs mapping.
            $unmapped = collect($state['subscriptions'])->contains(fn ($item) =>
                ($item['environment'] ?? null) === $environment
                && ! $this->isPromotional($item)
                && ! $this->catalog->resolve($this->storeIdentifiers($item), $item['base_plan_id'] ?? null, null));
            if (! $unmapped) app(MembershipTrialReservation::class)->releaseExpiredAfterVerification($user->id);
        });
    }

    private function applyState(User|Users $user, array $state, string $environment): void
    {
        // The complete authoritative snapshot replaces the prior store cache atomically.
        // Without this, refunded/transferred purchases absent from the response stay usable
        // as replacement evidence and can later be resurrected by an unrelated webhook.
        DB::table('verified_purchases')->where('user_id', $user->id)->where('environment', $environment)
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
        $valid = [];
        foreach ($state['subscriptions'] as $subscription) {
            $candidate = $this->subscriptionCandidate($user, $subscription, $environment);
            if ($candidate) $valid[] = $candidate;
            $this->syncScheduledChange($user, $subscription);
        }

        foreach ($state['purchases'] as $purchase) {
            $candidate = $this->purchaseCandidate($user, $purchase, $environment);
            if ($candidate) $valid[] = $candidate;
        }

        $policy = new ScheduledMembershipChangePolicy($this->catalog);
        foreach (DB::table('membership_attempts')->where('user_id', $user->id)
            ->where('operation', 'change')->where('status', 'scheduled')->get() as $attempt) {
            if ($policy->canCancel(json_decode($attempt->quote, true) ?: [], $state['subscriptions'], $environment, now()->getTimestampMs())) {
                $this->attemptStatus->transition((int) $attempt->id, 'cancelled', null, 'verified_store_cancellation');
            }
        }

        if ($valid !== []) {
            usort($valid, fn (array $a, array $b) => [$b['rank'], $b['expires_at']?->getTimestamp() ?? PHP_INT_MAX] <=> [$a['rank'], $a['expires_at']?->getTimestamp() ?? PHP_INT_MAX]);
            $this->publish($user, $valid[0]);
            $this->cancelExpiredAttempts($user);
            return;
        }

        // RevenueCat can be empty while a separately started account trial is valid.
        $access = DB::table('account_access')->where('user_id', $user->id)->first();
        if ($access?->access_source === 'account_trial' && $access->access_status === 'trial'
            && $access->access_ends_at && Carbon::parse($access->access_ends_at)->isFuture()) {
            $this->cancelExpiredAttempts($user);
            return;
        }

        // This was a successful, complete RevenueCat read. Retire store access when no current
        // verified entitlement remains; do not alter legacy or account-trial access here.
        if ($access?->access_source === 'store') {
            DB::table('account_access')->where('user_id', $user->id)->update([
                'effective_package' => null,
                'access_status' => 'expired',
                'access_ends_at' => $access->access_ends_at ?? now(),
                'updated_at' => now(),
            ]);
        }
        $this->cancelExpiredAttempts($user);
    }

    private function subscriptionCandidate(User|Users $user, array $item, string $environment): ?array
    {
        if ($this->isPromotional($item)) return $this->promotionalCandidate($item);
        if (($item['environment'] ?? null) !== $environment) return null;
        $entitlements = is_array($item['entitlements'] ?? null) ? ($item['entitlements']['items'] ?? []) : [];
        $package = $this->packageFromEntitlements($entitlements);
        $identifiers = $this->storeIdentifiers($item);
        $choice = $this->products->resolve(
            $user->id,
            $identifiers,
            $package,
            $item['base_plan_id'] ?? null,
            $environment,
            $item['store_subscription_identifier'] ?? $item['original_transaction_id'] ?? $item['id'] ?? null,
        );
        if (! $choice) {
            $this->products->logUnresolved([
                'user_id' => $user->id,
                'source' => 'reconciliation_subscription',
                'product_ids' => $identifiers,
                'base_plan_id' => $item['base_plan_id'] ?? null,
                'environment' => $environment,
            ]);
        }
        if (! $choice) {
            if (($item['gives_access'] ?? null) === true) {
                throw new RuntimeException('An active membership product could not be mapped.');
            }
            $this->recordSubscription($user, $item, null, $package, $environment, ($item['gives_access'] ?? null) === true);
            return null;
        }
        $package = $choice['package'];
        // A verified purchase may arrive after its trial has renewed or expired.
        // Settle that checkout before access checks, without granting expired access.
        $trialAttempt = $this->settleVerifiedTrialCheckout($user, $item, $choice);
        if (($item['gives_access'] ?? null) !== true) {
            $this->recordSubscription($user, $item, $choice, $package, $environment, false);
            return null;
        }

        $periodEnd = $this->milliseconds($item['ends_at'] ?? $item['current_period_ends_at'] ?? null);
        if (! $periodEnd || ! $periodEnd->isFuture()) {
            $this->recordSubscription($user, $item, $choice, $package, $environment, false, $periodEnd);
            return null;
        }
        $status = $item['status'] ?? 'unknown';
        $isTrial = $status === 'trialing';
        $renewalStatus = match ($item['auto_renewal_status'] ?? null) {
            'will_renew', 'has_already_renewed' => 'renewing',
            'will_not_renew', 'will_change_product', 'will_pause', 'requires_price_increase_consent' => 'cancelled',
            default => 'unknown',
        };
        $this->recordSubscription($user, $item, $choice, $package, $environment, true, $periodEnd, $renewalStatus);

        $selected = $package;
        $effective = $package;
        if ($isTrial) {
            $attempt = $trialAttempt;
            if ($attempt) {
                $quote = json_decode($attempt->quote, true) ?: [];
                $selected = $quote['selected_package'] ?? $package;
                $effective = $quote['trial_access_package'] ?? $package;
            }
        } else {
            $this->completeMatchingPurchaseAttempt(
                $user,
                $choice,
                $item['store_subscription_identifier'] ?? $item['original_transaction_id'] ?? null
            );
        }

        return [
            'selected_package' => $selected,
            'effective_package' => $effective,
            'payment_path' => $choice['path'],
            // gives_access plus the verified deadline above determine entitlement.
            // A billing warning must not revoke a valid grace period.
            'status' => $isTrial ? 'trial' : 'active',
            'source' => 'store',
            'product_id' => $choice['product_id'],
            'trial_ends_at' => $isTrial ? $periodEnd : null,
            'access_ends_at' => $periodEnd,
            'rank' => $package === 'premium' ? 2 : 1,
            'expires_at' => $periodEnd,
        ];
    }

    private function purchaseCandidate(User|Users $user, array $item, string $environment): ?array
    {
        if ($this->isPromotional($item)) return $this->promotionalCandidate($item);
        if (($item['environment'] ?? null) !== $environment || ($item['status'] ?? null) !== 'owned') return null;
        $entitlements = is_array($item['entitlements'] ?? null) ? ($item['entitlements']['items'] ?? []) : [];
        $package = $this->packageFromEntitlements($entitlements);
        $identifiers = $this->storeIdentifiers($item);
        $choice = $this->products->resolve(
            $user->id,
            $identifiers,
            $package,
            $item['base_plan_id'] ?? null,
            $environment,
            $item['store_purchase_identifier'] ?? null,
        );
        if (! $choice) {
            $this->products->logUnresolved([
                'user_id' => $user->id,
                'source' => 'reconciliation_purchase',
                'product_ids' => $identifiers,
                'base_plan_id' => $item['base_plan_id'] ?? null,
                'environment' => $environment,
            ]);
        }
        if (! $choice && $package !== null) throw new RuntimeException('An owned membership product could not be mapped.');
        // The purchases collection also contains unrelated challenge packs.
        if (! $choice) return null;
        if ($choice['path'] !== 'lifetime') return null;
        $package = $choice['package'];
        $this->recordPurchase($user, $item, $choice, $package, $environment);
        $this->completeMatchingPurchaseAttempt(
            $user,
            $choice,
            $item['store_purchase_identifier'] ?? null
        );
        return [
            'selected_package' => $package, 'effective_package' => $package, 'payment_path' => 'lifetime',
            'status' => 'active', 'source' => 'store', 'product_id' => $choice['product_id'],
            'trial_ends_at' => null, 'access_ends_at' => null, 'rank' => $package === 'premium' ? 2 : 1, 'expires_at' => null,
        ];
    }

    private function isPromotional(array $item): bool
    {
        return strtolower((string) ($item['store'] ?? '')) === 'promotional';
    }

    /** Only authoritative RevenueCat records may establish promotional access. */
    private function promotionalCandidate(array $item): ?array
    {
        if (! $this->isPromotional($item) || ($item['environment'] ?? null) !== 'production') return null;
        if (($item['gives_access'] ?? null) !== true) return null;
        $package = $this->packageFromEntitlements($item['entitlements']['items'] ?? []);
        if ($package === null) return null;
        $lifetime = array_key_exists('ends_at', $item) && $item['ends_at'] === null;
        $end = $this->milliseconds($item['ends_at'] ?? $item['current_period_ends_at'] ?? null);
        if (! $lifetime && (! $end || ! $end->isFuture())) return null;
        return [
            'selected_package' => $package, 'effective_package' => $package,
            'payment_path' => $lifetime ? 'lifetime' : null, 'status' => 'active', 'source' => 'store',
            'product_id' => $item['store_product_id'] ?? $item['product_id'] ?? null,
            'trial_ends_at' => null, 'access_ends_at' => $end,
            'rank' => $package === 'premium' ? 2 : 1, 'expires_at' => $end,
        ];
    }

    private function packageFromEntitlements(mixed $entitlements): ?string
    {
        if (! is_array($entitlements)) return null;
        $package = null;
        foreach ($entitlements as $entitlement) {
            if (! is_array($entitlement)) continue;
            if (($entitlement['state'] ?? null) !== 'active') continue;
            $lookup = strtolower((string) ($entitlement['lookup_key'] ?? ''));
            if (in_array($lookup, ['premium', 'premium_android', 'premium android', 'tier3', 'tier_3', 'tier3_android', 'tier_3_android'], true)) return 'premium';
            if (in_array($lookup, ['standard', 'standard_andriod', 'tier2', 'tier_2', 'tier2_android', 'tier_2_android'], true)) $package = 'standard';
        }
        return $package;
    }

    private function storeIdentifiers(array $item): array
    {
        if (! empty($item['store_product_id']) && is_string($item['store_product_id'])) return [$item['store_product_id']];
        if (! empty($item['product_id']) && is_string($item['product_id'])) return [$item['product_id']];
        $identifiers = [];
        $entitlements = $item['entitlements']['items'] ?? [];
        if (! is_array($entitlements)) return [];
        foreach ($entitlements as $entitlement) {
            if (! is_array($entitlement)) continue;
            $productsData = is_array($entitlement['products'] ?? null) ? $entitlement['products'] : [];
            $products = $productsData['items'] ?? [];
            if (! is_array($products)) continue;
            foreach ($products as $product) {
                if (is_array($product) && isset($product['store_identifier']) && is_string($product['store_identifier'])) {
                    $identifiers[] = $product['store_identifier'];
                }
            }
        }
        return array_values(array_unique(array_map('strval', $identifiers)));
    }

    private function settleVerifiedTrialCheckout(User|Users $user, array $item, array $choice): ?object
    {
        $transactionId = $item['store_subscription_identifier'] ?? $item['original_transaction_id'] ?? null;
        if (! is_string($transactionId) || $transactionId === '') return null;
        $attempt = $this->matchingAttempt($user, 'trial', $choice);
        $isTrial = ($item['status'] ?? null) === 'trialing';
        if (! $attempt && $isTrial) {
            $attempt = (new CompletedMembershipTrial())->find($user->id, $choice,
                [$transactionId, $item['original_transaction_id'] ?? null]);
        }
        if (! $attempt && ! $isTrial) return null;

        if ($attempt && $attempt->status !== 'completed') {
            $this->attemptStatus->transition((int) $attempt->id, 'completed', $transactionId, 'verified_revenuecat_trial');
        }
        $trialEnd = $isTrial
            ? $this->milliseconds($item['current_period_ends_at'] ?? $item['ends_at'] ?? null)
            : $this->milliseconds($item['current_period_starts_at'] ?? null);
        DB::table('membership_trials')->updateOrInsert(['user_id' => $user->id], [
            'status' => 'consumed', 'started_at' => $this->milliseconds($item['starts_at'] ?? null) ?? now(),
            'ends_at' => $trialEnd ?? now(), 'source' => 'store', 'transaction_id' => $transactionId,
            'reserved_at' => null, 'reservation_expires_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $attempt;
    }

    private function matchingAttempt(User|Users $user, string $operation, array $choice): ?object
    {
        $matches = [];
        foreach (DB::table('membership_attempts')->where('user_id', $user->id)->where('operation', $operation)->whereIn('status', ['pending', 'scheduled', 'cancelled'])->latest('created_at')->get() as $attempt) {
            $quote = json_decode($attempt->quote, true) ?: [];
            if (($quote['product_id'] ?? null) === $choice['store_product_id']
                && ($quote['base_plan_id'] ?? null) === $choice['base_plan_id']) $matches[] = $attempt;
        }
        // A previously abandoned quote for this product must not make the current
        // checkout ambiguous. Keep cancelled attempts only for late-receipt recovery.
        $current = array_values(array_filter($matches, fn ($attempt) => in_array($attempt->status, ['pending', 'scheduled'], true)));
        $candidates = $current !== [] ? $current : $matches;
        return count($candidates) === 1 ? $candidates[0] : null;
    }

    private function completeMatchingPurchaseAttempt(User|Users $user, array $choice, mixed $transactionId): void
    {
        if (! is_string($transactionId) || $transactionId === '') return;
        $attempts = [];
        foreach (['purchase', 'change'] as $operation) {
            $attempt = $this->matchingAttempt($user, $operation, $choice);
            if ($attempt) $attempts[] = $attempt;
        }
        if (count($attempts) !== 1) return;

        $this->attemptStatus->transition(
            (int) $attempts[0]->id,
            'completed',
            $transactionId,
            'verified_revenuecat_purchase',
        );
    }

    private function recordSubscription(User|Users $user, array $item, ?array $choice, ?string $package, string $environment, bool $active, ?Carbon $expiresAt = null, ?string $renewalStatus = null): void
    {
        $transaction = (string) ($item['store_subscription_identifier'] ?? $item['original_transaction_id'] ?? '');
        if ($transaction === '') return;
        $this->upsertPurchase($user, $transaction, $item['product_id'] ?? '', $choice, $package, $environment, $expiresAt ?? $this->milliseconds($item['ends_at'] ?? null), $renewalStatus, $active, $item);
    }

    private function recordPurchase(User|Users $user, array $item, array $choice, string $package, string $environment): void
    {
        $transaction = (string) ($item['store_purchase_identifier'] ?? $item['id'] ?? '');
        if ($transaction === '') return;
        $this->upsertPurchase($user, $transaction, $item['product_id'] ?? '', $choice, $package, $environment, null, null, true, $item);
    }

    private function upsertPurchase(User|Users $user, string $transaction, string $productId, ?array $choice, ?string $package, string $environment, ?Carbon $expiresAt, ?string $renewalStatus, bool $active, array $payload): void
    {
        DB::table('verified_purchases')->updateOrInsert([
            'store' => $payload['store'] ?? null, 'environment' => $environment, 'transaction_id' => $transaction,
        ], [
            'user_id' => $user->id,
            'original_transaction_id' => $payload['original_transaction_id'] ?? $payload['store_subscription_identifier'] ?? null,
            'product_id' => $choice['store_product_id'] ?? $productId,
            'base_plan_id' => $choice['base_plan_id'] ?? null,
            'entitlement' => $package, 'package' => $choice['package'] ?? $package,
            'payment_path' => $choice['path'] ?? null, 'renewal_status' => $renewalStatus,
            'expires_at' => $expiresAt, 'revoked_at' => $active ? null : now(), 'payload' => json_encode($payload),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function publish(User|Users $user, array $candidate): void
    {
        DB::table('account_access')->updateOrInsert(['user_id' => $user->id], [
            'selected_package' => $candidate['selected_package'], 'effective_package' => $candidate['effective_package'],
            'payment_path' => $candidate['payment_path'], 'access_status' => $candidate['status'],
            'access_source' => $candidate['source'], 'product_id' => $candidate['product_id'],
            'trial_ends_at' => $candidate['trial_ends_at'], 'access_ends_at' => $candidate['access_ends_at'],
            'updated_at' => now(), 'created_at' => now(),
        ]);
    }

    private function syncScheduledChange(User|Users $user, array $subscription): void
    {
        $pendingChanges = is_array($subscription['pending_changes'] ?? null) ? $subscription['pending_changes'] : [];
        $pending = is_array($pendingChanges['product'] ?? null) ? $pendingChanges['product'] : [];
        $pendingStoreIdentifier = $pending['store_identifier'] ?? null;
        if (! is_string($pendingStoreIdentifier) || $pendingStoreIdentifier === '') return;
        $pendingBasePlan = $pending['base_plan_id'] ?? $pending['base_plan'] ?? null;
        foreach (DB::table('membership_attempts')->where('user_id', $user->id)->where('operation', 'change')->where('status', 'pending')->get() as $attempt) {
            $quote = json_decode($attempt->quote, true) ?: [];
            $target = $this->catalog->resolve(
                [$pendingStoreIdentifier],
                is_string($pendingBasePlan) ? $pendingBasePlan : null,
                $quote['selected_package'] ?? null,
            );
            if (($quote['replacement_mode'] ?? null) === 'DEFERRED'
                && $target
                && ($quote['product_id'] ?? null) === $target['store_product_id']
                && ($quote['base_plan_id'] ?? null) === $target['base_plan_id']) {
                $this->attemptStatus->transition((int) $attempt->id, 'scheduled', null, 'verified_revenuecat_pending_change');
            }
        }
    }

    private function cancelExpiredAttempts(User|Users $user): void
    {
        $attempts = DB::table('membership_attempts')->where('user_id', $user->id)
            ->where('status', 'pending')->whereNotNull('expires_at')->where('expires_at', '<=', now())->get(['id']);
        foreach ($attempts as $attempt) {
            $this->attemptStatus->transition(
                (int) $attempt->id,
                'cancelled',
                null,
                'complete_revenuecat_reconciliation_found_no_matching_purchase',
            );
        }
    }

    private function milliseconds(mixed $value): ?Carbon
    {
        return is_numeric($value) ? Carbon::createFromTimestampMs((float) $value)->utc() : null;
    }
}
