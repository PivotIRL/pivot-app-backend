<?php

namespace Tests\Unit;

use App\Services\Membership\MembershipExpirationPolicy;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class MembershipExpirationPolicyTest extends TestCase
{
    public function test_trial_access_uses_its_deadline_and_fails_closed_when_missing(): void
    {
        $now = Carbon::parse('2026-10-20T12:00:00Z');
        $policy = new MembershipExpirationPolicy();

        $this->assertSame('trial', $policy->apply('trial', 'monthly', 'premium', '2026-10-20T12:01:00Z', null, $now)['status']);
        $this->assertSame('expired', $policy->apply('trial', 'monthly', 'premium', null, null, $now)['status']);
        $this->assertSame('expired', $policy->apply('trial', 'monthly', 'premium', '2026-10-20T12:00:00Z', null, $now)['status']);
    }

    public function test_expired_or_undated_recurring_access_is_not_effective(): void
    {
        $now = Carbon::parse('2026-10-20T12:00:00Z');
        $policy = new MembershipExpirationPolicy();

        $this->assertTrue($policy->apply('active', 'annual', 'standard', null, '2026-10-19T12:00:00Z', $now)['expired']);
        $this->assertTrue($policy->apply('billing_restricted', 'monthly', 'standard', null, null, $now)['expired']);
        $this->assertFalse($policy->apply('active', 'lifetime', 'premium', null, null, $now)['expired']);
        $this->assertTrue($policy->apply('active', 'lifetime', null, null, null, $now)['expired']);
    }
}
