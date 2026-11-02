<?php

namespace Tests\Feature\Organization;

use App\Organization\Enums\AuditAction;
use App\Organization\Enums\AuditActorType;
use App\Organization\Enums\PivotRole;
use App\Organization\Models\Organization;
use App\Organization\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Feature\Organization\Concerns\BuildsOrganizationActors;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    use BuildsOrganizationActors, RefreshDatabase;

    public function test_it_records_actor_target_and_redacts_secrets(): void
    {
        $organization = Organization::factory()->create();
        $actor = $this->makeUser();

        $log = app(AuditLogger::class)->record(
            action: AuditAction::ORGANIZATION_SUSPENDED,
            organization: $organization,
            target: $organization,
            metadata: ['reason' => 'test', 'token' => 'abc', 'nested' => ['client_secret' => 'xyz']],
            actor: $actor,
        );

        $this->assertSame($organization->id, $log->organization_id);
        $this->assertSame($actor->id, $log->actor_user_id);
        $this->assertSame(AuditActorType::USER, $log->actor_type);
        $this->assertSame('organization', $log->target_type);
        // jsonb does not keep key order, so compare without it.
        $this->assertEquals([
            'reason' => 'test',
            'token' => '[REDACTED]',
            'nested' => ['client_secret' => '[REDACTED]'],
        ], $log->fresh()->metadata);
    }

    public function test_actions_without_a_user_are_recorded_as_system(): void
    {
        $log = app(AuditLogger::class)->record(AuditAction::ORGANIZATION_SUSPENDED);

        $this->assertNull($log->actor_user_id);
        $this->assertSame(AuditActorType::SYSTEM, $log->actor_type);
    }

    public function test_audit_rows_are_immutable(): void
    {
        $log = app(AuditLogger::class)->record(AuditAction::ORGANIZATION_SUSPENDED);

        $this->expectException(LogicException::class);
        $log->update(['action' => AuditAction::ORGANIZATION_REMOVED]);
    }

    public function test_pivot_role_command_grants_and_revokes_with_audit(): void
    {
        $user = $this->makeUser();

        $this->artisan('pivot:role', ['email' => $user->email, 'role' => 'PIVOT_SUPPORT'])->assertSuccessful();
        $this->assertSame(PivotRole::PIVOT_SUPPORT, $user->fresh()->pivot_role);

        $this->artisan('pivot:role', ['email' => $user->email, '--revoke' => true])->assertSuccessful();
        $this->assertNull($user->fresh()->pivot_role);

        $this->assertDatabaseHas('organization_audit_logs', ['action' => AuditAction::PIVOT_ROLE_GRANTED->value, 'target_id' => (string) $user->id]);
        $this->assertDatabaseHas('organization_audit_logs', ['action' => AuditAction::PIVOT_ROLE_REVOKED->value, 'target_id' => (string) $user->id]);
    }

    public function test_pivot_role_command_rejects_unknown_roles(): void
    {
        $user = $this->makeUser();

        $this->artisan('pivot:role', ['email' => $user->email, 'role' => 'ORG_OWNER'])->assertFailed();
        $this->assertNull($user->fresh()->pivot_role);
    }
}
