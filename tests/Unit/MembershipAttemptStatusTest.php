<?php

namespace Tests\Unit;

use App\Services\Membership\MembershipAttemptStatus;
use DomainException;
use PHPUnit\Framework\TestCase;

class MembershipAttemptStatusTest extends TestCase
{
    public function test_all_supported_attempt_states_have_only_verified_transitions(): void
    {
        $statuses = new MembershipAttemptStatus();

        $statuses->validateTransition('pending', 'completed', 'store-transaction-1', 'verified_revenuecat_webhook');
        $statuses->validateTransition('pending', 'scheduled', null, 'verified_revenuecat_deferred_product_change');
        $statuses->validateTransition('pending', 'cancelled', null, 'complete_revenuecat_reconciliation_found_no_matching_purchase');
        $statuses->validateTransition('pending', 'failed', null, 'verified_store_failure');
        $statuses->validateTransition('scheduled', 'cancelled', null, 'verified_store_cancellation');
        $statuses->validateTransition('scheduled', 'completed', 'store-transaction-2', 'verified_revenuecat_webhook');
        $statuses->validateTransition('cancelled', 'completed', 'late-store-transaction', 'verified_revenuecat_webhook');
        $statuses->validateTransition('cancelled', 'scheduled', null, 'verified_revenuecat_deferred_product_change');

        $this->expectException(DomainException::class);
        $statuses->validateTransition('pending', 'completed', null, 'client-claimed-success');
    }

    public function test_invalid_or_terminal_transitions_are_rejected(): void
    {
        $statuses = new MembershipAttemptStatus();

        try {
            $statuses->validateTransition('completed', 'failed', null, 'timeout');
            $this->fail('A completed attempt must be terminal.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $this->expectException(DomainException::class);
        $statuses->validateTransition('pending', 'cancelled');
    }

    public function test_a_timeout_cannot_be_used_as_failure_evidence(): void
    {
        $this->expectException(DomainException::class);
        (new MembershipAttemptStatus())->validateTransition('pending', 'failed', null, 'network_timeout');
    }
}
