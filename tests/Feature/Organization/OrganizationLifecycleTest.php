<?php

namespace Tests\Feature\Organization;

use App\Models\User;
use App\Organization\Enums\AuditAction;
use App\Organization\Enums\AuditActorType;
use App\Organization\Enums\MembershipStatus;
use App\Organization\Enums\OrganizationRole;
use App\Organization\Enums\OrganizationStatus;
use App\Organization\Enums\PivotRole;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationAuditLog;
use App\Organization\Models\OrganizationMembership;
use App\Organization\Services\OrganizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Organization\Concerns\BuildsOrganizationActors;
use Tests\TestCase;

/**
 * Contract lifecycle rules: membership restoration after renewal, reactivation dates, update audit.
 */
class OrganizationLifecycleTest extends TestCase
{
    use BuildsOrganizationActors, RefreshDatabase;

    // Membership restoration after expiry + renewal

    public function test_renewal_restores_only_eligible_contract_expired_memberships(): void
    {
        $organization = Organization::factory()->create(['seat_limit' => 10, 'contract_end_at' => now()->addDay()]);
        $other = Organization::factory()->create();

        $eligible = $this->membership($organization);
        $offboarded = $this->membership($organization, [
            'status' => MembershipStatus::DEACTIVATED,
            'deactivated_at' => now(),
            'deactivation_reason' => 'offboarded',
        ]);
        $ownExpiryPassed = $this->membership($organization, ['expires_at' => now()->addDays(2)]);
        $joinedElsewhere = $this->membership($organization);

        $this->travel(3)->days();
        app(OrganizationService::class)->expirePastContracts();

        // While A was expired, this person joined another organization.
        $this->membership($other, [], $joinedElsewhere->user);

        $this->renew($organization, seatLimit: 10)->assertOk()->assertJsonPath('status', 'active');

        $this->assertSame(MembershipStatus::ACTIVE, $eligible->fresh()->status);
        $this->assertNull($eligible->fresh()->deactivation_reason);
        $this->assertSame(MembershipStatus::DEACTIVATED, $offboarded->fresh()->status);
        $this->assertSame(MembershipStatus::EXPIRED, $ownExpiryPassed->fresh()->status);
        $this->assertSame(MembershipStatus::EXPIRED, $joinedElsewhere->fresh()->status);

        $log = $this->lastAudit($organization, AuditAction::ORGANIZATION_MEMBERSHIPS_RESTORED);
        $this->assertSame(1, $log->metadata['restored']);
        $this->assertSame(AuditActorType::PIVOT_STAFF, $log->actor_type);
    }

    public function test_restoration_respects_the_new_seat_limit_oldest_members_first(): void
    {
        $organization = Organization::factory()->create(['seat_limit' => 10, 'contract_end_at' => now()->addDay()]);
        $oldest = $this->membership($organization, ['joined_at' => now()->subYears(3)]);
        $middle = $this->membership($organization, ['joined_at' => now()->subYears(2)]);
        $newest = $this->membership($organization, ['joined_at' => now()->subYear()]);

        $this->travel(3)->days();
        app(OrganizationService::class)->expirePastContracts();

        $this->renew($organization, seatLimit: 2)->assertOk();

        $this->assertSame(MembershipStatus::ACTIVE, $oldest->fresh()->status);
        $this->assertSame(MembershipStatus::ACTIVE, $middle->fresh()->status);
        $this->assertSame(MembershipStatus::EXPIRED, $newest->fresh()->status);
        $this->assertSame(1, $this->lastAudit($organization, AuditAction::ORGANIZATION_MEMBERSHIPS_RESTORED)->metadata['not_restored_no_free_seat']);

        // A later renewal with more seats restores the member who was left out.
        $this->renew($organization, seatLimit: 5)->assertOk();
        $this->assertSame(MembershipStatus::ACTIVE, $newest->fresh()->status);
    }

    public function test_future_start_renewal_restores_members_only_when_the_contract_starts(): void
    {
        $organization = Organization::factory()->create(['contract_end_at' => now()->addDay()]);
        $member = $this->membership($organization);

        $this->travel(3)->days();
        app(OrganizationService::class)->expirePastContracts();

        $this->renew($organization, seatLimit: 10, startsAt: now()->addDays(5))
            ->assertOk()
            ->assertJsonPath('status', 'pending');
        $this->assertSame(MembershipStatus::EXPIRED, $member->fresh()->status);

        $this->travel(6)->days();
        app(OrganizationService::class)->activateStartedContracts();

        $this->assertSame(OrganizationStatus::ACTIVE, $organization->fresh()->status);
        $this->assertSame(MembershipStatus::ACTIVE, $member->fresh()->status);

        $log = $this->lastAudit($organization, AuditAction::ORGANIZATION_MEMBERSHIPS_RESTORED);
        $this->assertSame(AuditActorType::SYSTEM, $log->actor_type);
        $this->assertNull($log->actor_user_id);
    }

    // Reactivation and contract dates

    public function test_reactivating_with_a_current_contract_makes_the_organization_active(): void
    {
        $organization = Organization::factory()->suspended()->create([
            'contract_start_at' => now()->subMonth(),
            'contract_end_at' => now()->addMonth(),
        ]);

        $this->reactivate($organization)->assertOk()->assertJsonPath('status', 'active');
    }

    public function test_reactivating_before_the_contract_starts_lifts_the_suspension_to_pending(): void
    {
        $organization = Organization::factory()->suspended()->create([
            'contract_start_at' => now()->addWeek(),
            'contract_end_at' => now()->addYear(),
        ]);

        $this->reactivate($organization)->assertOk()->assertJsonPath('status', 'pending');

        $log = $this->lastAudit($organization, AuditAction::ORGANIZATION_REACTIVATED);
        $this->assertSame('pending', $log->metadata['new_status']);

        $this->travel(8)->days();
        app(OrganizationService::class)->activateStartedContracts();
        $this->assertSame(OrganizationStatus::ACTIVE, $organization->fresh()->status);
    }

    public function test_reactivating_after_the_contract_has_ended_is_refused(): void
    {
        $organization = Organization::factory()->suspended()->create([
            'contract_start_at' => now()->subYear(),
            'contract_end_at' => now()->subDay(),
        ]);

        $this->reactivate($organization)->assertStatus(409);
        $this->assertSame(OrganizationStatus::SUSPENDED, $organization->fresh()->status);
    }

    public function test_only_super_admin_can_reactivate(): void
    {
        $organization = Organization::factory()->suspended()->create();

        foreach ([$this->makeStaff(PivotRole::PIVOT_SUPPORT), $this->makeOrgUser($organization, OrganizationRole::ORG_OWNER)] as $actor) {
            Sanctum::actingAs($actor);
            $this->postJson($this->url($organization, 'reactivate'), ['reason' => 'x'])->assertForbidden();
        }

        $this->assertSame(OrganizationStatus::SUSPENDED, $organization->fresh()->status);
    }

    // Update audit records only real changes

    public function test_name_only_update_records_only_the_name(): void
    {
        $organization = Organization::factory()->create();
        Sanctum::actingAs($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $this->putJson("/api/v1/organizations/{$organization->id}", ['name' => 'Renamed Ltd'])->assertOk();

        $log = $this->lastAudit($organization, AuditAction::ORGANIZATION_UPDATED);
        $this->assertSame(['name'], $log->metadata['changed_fields']);
        $this->assertArrayNotHasKey('previous_status', $log->metadata);
    }

    public function test_contract_field_update_without_status_change_does_not_record_status(): void
    {
        $organization = Organization::factory()->create(['status' => OrganizationStatus::ACTIVE, 'seat_limit' => 50]);
        Sanctum::actingAs($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $this->putJson("/api/v1/organizations/{$organization->id}", ['seat_limit' => 75])->assertOk();

        $this->assertSame(['seat_limit'], $this->lastAudit($organization, AuditAction::ORGANIZATION_UPDATED)->metadata['changed_fields']);
    }

    public function test_update_with_unchanged_values_writes_no_update_audit_row(): void
    {
        $organization = Organization::factory()->create();
        Sanctum::actingAs($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $this->putJson("/api/v1/organizations/{$organization->id}", [
            'name' => $organization->name,
            'seat_limit' => $organization->seat_limit,
        ])->assertOk();

        $this->assertDatabaseMissing('organization_audit_logs', [
            'organization_id' => $organization->id,
            'action' => AuditAction::ORGANIZATION_UPDATED->value,
        ]);
    }

    public function test_update_that_changes_status_records_the_status_change(): void
    {
        $organization = Organization::factory()->create(['status' => OrganizationStatus::ACTIVE]);
        Sanctum::actingAs($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $this->putJson("/api/v1/organizations/{$organization->id}", [
            'contract_start_at' => now()->addMonth()->toISOString(),
        ])->assertOk()->assertJsonPath('status', 'pending');

        $log = $this->lastAudit($organization, AuditAction::ORGANIZATION_UPDATED);
        $this->assertEqualsCanonicalizing(['contract_start_at', 'status'], $log->metadata['changed_fields']);
        $this->assertSame('active', $log->metadata['previous_status']);
        $this->assertSame('pending', $log->metadata['new_status']);
    }

    // Helpers

    private function membership(Organization $organization, array $attributes = [], ?User $user = null): OrganizationMembership
    {
        return OrganizationMembership::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => ($user ?? $this->makeUser())->id,
            ...$attributes,
        ]);
    }

    private function renew(Organization $organization, int $seatLimit, $startsAt = null)
    {
        Sanctum::actingAs($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        return $this->postJson("/api/v1/organizations/{$organization->id}/contracts/renew", [
            'package_code' => 'STANDARD',
            'seat_limit' => $seatLimit,
            'contract_start_at' => ($startsAt ?? now()->subMinute())->toISOString(),
            'contract_end_at' => now()->addYear()->toISOString(),
        ]);
    }

    private function reactivate(Organization $organization)
    {
        Sanctum::actingAs($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        return $this->postJson($this->url($organization, 'reactivate'), ['reason' => 'Resolved']);
    }

    private function lastAudit(Organization $organization, AuditAction $action): OrganizationAuditLog
    {
        return OrganizationAuditLog::query()
            ->where('organization_id', $organization->id)
            ->where('action', $action)
            ->latest('id')
            ->firstOrFail();
    }

    private function url(Organization $organization, string $path): string
    {
        return "/api/v1/organizations/{$organization->id}/{$path}";
    }
}
