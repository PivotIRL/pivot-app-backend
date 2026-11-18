<?php

namespace App\Services\Membership;

use DomainException;
use Illuminate\Support\Facades\DB;

class MembershipAttemptStatus
{
    private const TRANSITIONS = [
        'pending' => ['completed', 'scheduled', 'cancelled', 'failed'],
        'scheduled' => ['completed', 'cancelled', 'failed'],
        // A late verified store response can recover a locally retired quote.
        'cancelled' => ['completed', 'scheduled'],
        'completed' => [],
        'failed' => [],
    ];

    private const VERIFIED_EVIDENCE = [
        'verified_revenuecat_webhook',
        'verified_revenuecat_trial',
        'verified_revenuecat_purchase',
        'verified_revenuecat_pending_change',
        'verified_revenuecat_deferred_product_change',
    ];

    public function transition(
        int $attemptId,
        string $to,
        ?string $verifiedTransactionId = null,
        ?string $evidence = null,
    ): bool {
        $attempt = DB::table('membership_attempts')->where('id', $attemptId)->lockForUpdate()->first();
        if (! $attempt) return false;
        $this->validateTransition($attempt->status, $to, $verifiedTransactionId, $evidence);

        return DB::table('membership_attempts')->where('id', $attemptId)->where('status', $attempt->status)->update([
            'status' => $to,
            'verified_transaction_id' => $verifiedTransactionId ?? $attempt->verified_transaction_id,
            'updated_at' => now(),
        ]) === 1;
    }

    public function validateTransition(
        string $from,
        string $to,
        ?string $verifiedTransactionId = null,
        ?string $evidence = null,
    ): void {
        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new DomainException("Invalid membership attempt transition: {$from} -> {$to}.");
        }
        if (in_array($to, ['completed', 'scheduled'], true) && ! in_array($evidence, self::VERIFIED_EVIDENCE, true)) {
            throw new DomainException("Membership attempt status {$to} requires verified RevenueCat evidence.");
        }
        if ($to === 'completed' && (! is_string($verifiedTransactionId) || $verifiedTransactionId === '')) {
            throw new DomainException('A completed membership attempt requires a verified transaction id.');
        }
        if ($to === 'cancelled' && ! in_array($evidence, [
            'complete_revenuecat_reconciliation_found_no_matching_purchase',
            'verified_store_cancellation',
        ], true)) {
            throw new DomainException('Membership attempt status cancelled requires a trusted terminal-state reason.');
        }
        if ($to === 'failed' && $evidence !== 'verified_store_failure') {
            throw new DomainException('Membership attempt status failed requires verified store-failure evidence.');
        }
    }
}
