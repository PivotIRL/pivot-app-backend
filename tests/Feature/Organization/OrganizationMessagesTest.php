<?php

namespace Tests\Feature\Organization;

use App\Organization\Enums\OrganizationPermission;
use App\Organization\Enums\OrganizationRole;
use App\Organization\Enums\PivotRole;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Organization\Concerns\BuildsOrganizationActors;
use Tests\TestCase;

class OrganizationMessagesTest extends TestCase
{
    use BuildsOrganizationActors, RefreshDatabase;

    public function test_every_permission_has_its_own_denied_message(): void
    {
        $messages = array_map(fn (OrganizationPermission $p) => $p->deniedMessage(), OrganizationPermission::cases());

        $this->assertCount(count(OrganizationPermission::cases()), array_unique($messages));
    }

    public function test_listing_organizations_without_a_staff_role_explains_why(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->getJson('/api/v1/organizations')
            ->assertForbidden()
            ->assertExactJson(['message' => 'Only Pivot staff can view the list of all organizations.']);
    }

    public function test_org_admin_is_told_which_action_is_not_allowed(): void
    {
        $organization = Organization::factory()->create();
        Sanctum::actingAs($this->makeOrgUser($organization, OrganizationRole::ORG_ADMIN));

        $this->postJson("/api/v1/organizations/{$organization->id}/suspend", ['reason' => 'Test'])
            ->assertForbidden()
            ->assertExactJson(['message' => 'You do not have permission to suspend or reactivate this organization.']);
    }

    public function test_managing_your_own_membership_has_a_specific_message(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->makeOrgUser($organization, OrganizationRole::ORG_ADMIN);
        $membership = OrganizationMembership::where('user_id', $admin->id)->firstOrFail();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/organizations/{$organization->id}/members/{$membership->id}/suspend", ['reason' => 'Test'])
            ->assertForbidden()
            ->assertExactJson(['message' => 'You cannot change your own membership.']);
    }

    public function test_managing_a_higher_ranked_member_has_a_specific_message(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->makeOrgUser($organization, OrganizationRole::ORG_OWNER);
        $membership = OrganizationMembership::where('user_id', $owner->id)->firstOrFail();
        Sanctum::actingAs($this->makeOrgUser($organization, OrganizationRole::ORG_ADMIN));

        $this->postJson("/api/v1/organizations/{$organization->id}/members/{$membership->id}/suspend", ['reason' => 'Test'])
            ->assertForbidden()
            ->assertExactJson(['message' => 'You can only manage members with a lower role than yours.']);
    }

    public function test_role_changes_separate_missing_permission_from_the_rank_rule(): void
    {
        $organization = Organization::factory()->create();
        $target = $this->makeMember($organization);
        $payload = ['user_id' => $target->id, 'role' => OrganizationRole::ORG_OWNER->value];

        Sanctum::actingAs($this->makeOrgUser($organization, OrganizationRole::ORG_ANALYST));
        $this->postJson("/api/v1/organizations/{$organization->id}/roles", $payload)
            ->assertForbidden()
            ->assertExactJson(['message' => "You do not have permission to manage this organization's roles."]);

        Sanctum::actingAs($this->makeOrgUser($organization, OrganizationRole::ORG_ADMIN));
        $this->postJson("/api/v1/organizations/{$organization->id}/roles", $payload)
            ->assertForbidden()
            ->assertExactJson(['message' => 'You can only assign roles lower than your own.']);
    }

    public function test_revoking_your_own_role_uses_revoke_wording(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->makeUser();
        $this->makeMember($organization, $admin);
        $assignment = $this->grantRole($admin, $organization, OrganizationRole::ORG_ADMIN);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/v1/organizations/{$organization->id}/roles/{$assignment->id}")
            ->assertForbidden()
            ->assertExactJson(['message' => 'You can only revoke roles lower than your own.']);
    }

    public function test_allowed_actions_are_still_allowed(): void
    {
        $organization = Organization::factory()->create();
        Sanctum::actingAs($this->makeStaff(PivotRole::PIVOT_SUPPORT));

        $this->getJson('/api/v1/organizations')->assertOk();
        $this->getJson("/api/v1/organizations/{$organization->id}")->assertOk();
    }

    public function test_organization_validation_uses_readable_messages(): void
    {
        Sanctum::actingAs($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $this->postJson('/api/v1/organizations', [
            'name' => 'Acme',
            'slug' => 'not a slug',
            'organization_type' => 'NOPE',
            'package_code' => 'CUSTOM',
            'seat_limit' => 0,
            'contract_start_at' => '2030-01-02',
            'contract_end_at' => '2030-01-01',
            'country_code' => 'AUS',
            'pricing_config' => ['currency' => 'DOLLARS'],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Invalid data provided.')
            ->assertJsonPath('errors.slug.0', 'The slug can only contain letters, numbers, dashes and underscores.')
            ->assertJsonPath('errors.seat_limit.0', 'The seat limit field must be at least 1.')
            ->assertJsonPath('errors.contract_end_at.0', 'The contract end date must be in the future and after the contract start date.')
            ->assertJsonPath('errors.country_code.0', 'The country code must be a 2-letter code, for example AU.')
            ->assertJsonFragment(['pricing_config.currency' => ['The currency must be a 3-letter code, for example AUD.']])
            ->assertJsonPath('errors.entitlements.0', 'Entitlements are required for the CUSTOM package.');
    }

    public function test_enum_errors_list_the_allowed_values(): void
    {
        $organization = Organization::factory()->create();
        Sanctum::actingAs($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $roles = implode(', ', array_column(OrganizationRole::cases(), 'value'));

        $this->postJson("/api/v1/organizations/{$organization->id}/roles", [
            'user_id' => $this->makeUser()->id,
            'role' => 'NOPE',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.role.0', "The role must be one of: {$roles}.");
    }

    public function test_domain_and_required_field_messages(): void
    {
        $organization = Organization::factory()->create();
        Sanctum::actingAs($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $this->postJson("/api/v1/organizations/{$organization->id}/domains", ['domain' => 'not a domain'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.domain.0', 'Enter a valid domain, for example example.com.');

        $this->postJson('/api/v1/corporate/join/code', [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'A redemption code is required.');

        $this->postJson('/api/v1/corporate/invitations/accept', [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.token.0', 'An invitation token is required.');

        $this->getJson('/api/v1/organizations?per_page=500')
            ->assertUnprocessable()
            ->assertJsonPath('errors.per_page.0', 'The results per page field must be between 1 and 100.');
    }

    public function test_update_request_inherits_the_shared_messages(): void
    {
        $organization = Organization::factory()->create();
        Sanctum::actingAs($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $this->putJson("/api/v1/organizations/{$organization->id}", ['slug' => 'not a slug'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.slug.0', 'The slug can only contain letters, numbers, dashes and underscores.');
    }
}
