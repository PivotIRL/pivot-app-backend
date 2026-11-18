<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\Membership\MembershipEnvironment;
use Tests\TestCase;

class MembershipEnvironmentTest extends TestCase
{
    public function test_production_is_default_and_only_exact_allowlisted_ids_use_sandbox(): void
    {
        config(['membership.store_environment' => 'production', 'membership.sandbox_user_ids' => ' 96, 123 ']);
        $policy = new MembershipEnvironment();
        foreach ([96 => 'sandbox', 123 => 'sandbox', 9 => 'production', 196 => 'production'] as $id => $expected) {
            $user = (new User())->forceFill(['id' => $id]);
            $this->assertSame($expected, $policy->forUser($user));
            $this->assertTrue($policy->accepts($user, strtoupper($expected)));
            $this->assertFalse($policy->accepts($user, $expected === 'sandbox' ? 'production' : 'sandbox'));
            $this->assertFalse($policy->accepts($user, 'unknown'));
        }
    }

    public function test_staging_is_sandbox_for_every_account(): void
    {
        config(['membership.store_environment' => 'sandbox', 'membership.sandbox_user_ids' => '']);
        $policy = new MembershipEnvironment();
        $user = (new User())->forceFill(['id' => 96]);
        $this->assertSame('sandbox', $policy->forUser($user));
        $this->assertFalse($policy->accepts($user, 'production'));
    }

    public function test_invalid_configuration_does_not_silently_grant_access(): void
    {
        config(['membership.store_environment' => 'both']);
        $this->expectException(\RuntimeException::class);
        (new MembershipEnvironment())->forUser((new User())->forceFill(['id' => 96]));
    }
}
