<?php

namespace Tests\Feature\Organization;

use App\Organization\Enums\AuditAction;
use App\Organization\Enums\OrganizationRole;
use App\Organization\Enums\OrganizationStatus;
use App\Organization\Enums\PivotRole;
use App\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Organization\Concerns\BuildsOrganizationActors;
use Tests\TestCase;

class OrganizationControlTest extends TestCase
{
    use BuildsOrganizationActors, RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
    }

    public function test_super_admin_can_suspend_reactivate_and_remove_any_organization(): void
    {
        Sanctum::actingAs($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $this->postJson($this->url('suspend'), ['reason' => 'Unpaid invoice'])
            ->assertOk()
            ->assertJsonPath('status', OrganizationStatus::SUSPENDED->value);

        $this->postJson($this->url('reactivate'), ['reason' => 'Invoice paid'])
            ->assertOk()
            ->assertJsonPath('status', OrganizationStatus::ACTIVE->value);

        $this->deleteJson($this->url(), ['reason' => 'Contract terminated'])->assertOk();

        $this->assertSoftDeleted($this->organization);

        foreach ([AuditAction::ORGANIZATION_SUSPENDED, AuditAction::ORGANIZATION_REACTIVATED, AuditAction::ORGANIZATION_REMOVED] as $action) {
            $this->assertDatabaseHas('organization_audit_logs', [
                'organization_id' => $this->organization->id,
                'action' => $action->value,
                'target_type' => 'organization',
                'target_id' => $this->organization->id,
            ]);
        }
    }

    public function test_suspending_twice_or_reactivating_an_active_organization_conflicts(): void
    {
        Sanctum::actingAs($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $this->postJson($this->url('reactivate'), ['reason' => 'x'])->assertStatus(409);
        $this->postJson($this->url('suspend'), ['reason' => 'x'])->assertOk();
        $this->postJson($this->url('suspend'), ['reason' => 'x'])->assertStatus(409);
    }

    public function test_owner_and_support_cannot_suspend_or_remove(): void
    {
        $actors = [
            $this->makeOrgUser($this->organization, OrganizationRole::ORG_OWNER),
            $this->makeStaff(PivotRole::PIVOT_SUPPORT),
        ];

        foreach ($actors as $actor) {
            Sanctum::actingAs($actor);

            $this->postJson($this->url('suspend'), ['reason' => 'x'])->assertForbidden();
            $this->deleteJson($this->url(), ['reason' => 'x'])->assertForbidden();
        }

        $this->assertSame(OrganizationStatus::ACTIVE, $this->organization->fresh()->status);
    }

    public function test_reason_is_required(): void
    {
        Sanctum::actingAs($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $this->postJson($this->url('suspend'))->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    private function url(string $path = ''): string
    {
        return rtrim("/api/v1/organizations/{$this->organization->id}/{$path}", '/');
    }
}
