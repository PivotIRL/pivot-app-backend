<?php

namespace App\Services\Membership;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/** A reservation is a checkout to resume, never evidence that a trial was consumed. */
class MembershipTrialReservation
{
    public function __construct(private readonly MembershipProductCatalog $catalog) {}

    /** Caller verifies RevenueCat and holds the user lock; late receipts can still complete cancelled attempts. */
    public function cancelUnpaidAfterVerification(int $userId): void
    {
        $trial = DB::table('membership_trials')->where('user_id', $userId)->lockForUpdate()->first();
        if ($trial?->status !== 'reserved') return;

        foreach (DB::table('membership_attempts')->where('user_id', $userId)
            ->where('operation', 'trial')->where('status', 'pending')->get(['id']) as $attempt) {
            app(MembershipAttemptStatus::class)->transition((int) $attempt->id, 'cancelled', null,
                'complete_revenuecat_reconciliation_found_no_matching_purchase');
        }
        DB::table('membership_trials')->where('user_id', $userId)->where('status', 'reserved')->update([
            'status' => 'eligible', 'reserved_at' => null, 'reservation_expires_at' => null, 'updated_at' => now(),
        ]);
    }

    /** Called only under the user lock after a complete, successful store reconciliation. */
    public function releaseExpiredAfterVerification(int $userId): void
    {
        $trial = DB::table('membership_trials')->where('user_id', $userId)->lockForUpdate()->first();
        if ($trial?->status !== 'reserved'
            || ($trial->reservation_expires_at && now()->lessThan($trial->reservation_expires_at))) return;

        DB::table('membership_trials')->where('user_id', $userId)->where('status', 'reserved')->update([
            'status' => 'eligible', 'reserved_at' => null, 'reservation_expires_at' => null, 'updated_at' => now(),
        ]);
    }

    public function forUser(int $userId, ?object $trial): ?array
    {
        if ($trial?->status !== 'reserved') return null;

        $matches = [];
        foreach (DB::table('membership_attempts')->where('user_id', $userId)
            ->where('operation', 'trial')->where('status', 'pending')->get() as $attempt) {
            $quote = json_decode($attempt->quote, true);
            if (! is_array($quote) || ($quote['attempt_id'] ?? null) !== $attempt->attempt_id
                || ! is_string($quote['expires_at'] ?? null) || empty($quote['expires_at'])
                || empty($quote['offer_id'])) continue;
            try {
                Carbon::parse($quote['expires_at']);
            } catch (\Throwable) {
                continue;
            }
            $product = $this->catalog->resolve([$quote['product_id'] ?? null], $quote['base_plan_id'] ?? null, null);
            if (! $product || $product['path'] === 'lifetime') continue;
            $matches[] = [...$quote, 'selected_package' => $product['package'], 'payment_path' => $product['path']];
        }

        // Never guess which checkout owns an account-wide trial reservation.
        return count($matches) === 1 ? $matches[0] : null;
    }
}
