<?php

namespace Tests\Feature\Organization;

use App\Organization\Enums\AuditAction;
use App\Organization\Enums\MembershipStatus;
use App\Organization\Enums\OrganizationDomainStatus;
use App\Organization\Enums\OrganizationRole;
use App\Organization\Enums\OrganizationStatus;
use App\Organization\Enums\PivotRole;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationContract;
use App\Organization\Models\OrganizationDomain;
use App\Organization\Models\OrganizationMembership;
use App\Organization\Models\OrganizationRedemptionCode;
use App\Organization\Services\OrganizationDomainVerifier;
use App\Organization\Services\OrganizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\Feature\Organization\Concerns\BuildsOrganizationActors;
use Tests\TestCase;

class OrganizationB3Test extends TestCase
{
    use BuildsOrganizationActors, RefreshDatabase;

    public function test_pivot_super_admin_can_create_custom_organization_with_contract_and_entitlements(): void
    {
        Sanctum::actingAs($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $this->postJson('/api/v1/organizations', [
            'name' => 'Northwind Research',
            'slug' => 'northwind-research',
            'organization_type' => 'CORPORATE',
            'package_code' => 'CUSTOM',
            'seat_limit' => 80,
            'contract_start_at' => now()->subDay()->toISOString(),
            'contract_end_at' => now()->addYear()->toISOString(),
            'timezone' => 'Europe/London',
            'country_code' => 'GB',
            'pricing_config' => [
                'currency' => 'GBP',
                'unit_price' => 18.5,
                'discount_percent' => 10,
                'minimum_seats' => 20,
                'platform_fee' => 100,
                'volume_bands' => [
                    ['minimum_seats' => 100, 'unit_price' => 15, 'discount_percent' => 20],
                ],
            ],
            'entitlements' => [
                ['feature_key' => 'advanced_reports', 'value_json' => ['enabled' => true]],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('status', OrganizationStatus::ACTIVE->value)
            ->assertJsonPath('current_contract.package_code', 'CUSTOM')
            ->assertJsonPath('current_contract.pricing_config.currency', 'GBP');

        $organization = Organization::query()->where('slug', 'northwind-research')->firstOrFail();
        $this->assertDatabaseHas('organization_contracts', [
            'organization_id' => $organization->id,
            'status' => OrganizationStatus::ACTIVE->value,
            'seat_limit' => 80,
        ]);
        $this->assertDatabaseHas('organization_entitlements', [
            'organization_id' => $organization->id,
            'feature_key' => 'advanced_reports',
        ]);
        $this->assertDatabaseHas('organization_audit_logs', [
            'organization_id' => $organization->id,
            'action' => AuditAction::ORGANIZATION_CREATED->value,
        ]);

        $this->getJson('/api/v1/organizations')
            ->assertOk()
            ->assertJsonFragment(['id' => $organization->id]);
        $this->getJson("/api/v1/organizations/{$organization->id}/contracts")
            ->assertOk()
            ->assertJsonPath('0.package_code', 'CUSTOM');
    }

    public function test_contract_renewal_keeps_history_and_audits_the_new_contract(): void
    {
        $organization = Organization::factory()->create();
        Sanctum::actingAs($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $this->postJson("/api/v1/organizations/{$organization->id}/contracts/renew", [
            'package_code' => 'PREMIUM',
            'seat_limit' => 120,
            'contract_start_at' => now()->toISOString(),
            'contract_end_at' => now()->addYear()->toISOString(),
            'pricing_config' => ['currency' => 'USD', 'unit_price' => 25],
        ])
            ->assertOk()
            ->assertJsonPath('package_code', 'PREMIUM')
            ->assertJsonPath('current_contract.status', OrganizationStatus::ACTIVE->value);

        $this->assertDatabaseCount('organization_contracts', 2);
        $this->assertDatabaseHas('organization_contracts', [
            'organization_id' => $organization->id,
            'package_code' => 'STANDARD',
            'status' => OrganizationStatus::EXPIRED->value,
        ]);
        $this->assertDatabaseHas('organization_audit_logs', [
            'organization_id' => $organization->id,
            'action' => AuditAction::ORGANIZATION_CONTRACT_RENEWED->value,
        ]);
    }

    public function test_domain_is_normalized_and_requires_a_matching_dns_record_to_verify(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->makeOrgUser($organization, OrganizationRole::ORG_OWNER);
        Sanctum::actingAs($owner);

        $this->postJson($this->organizationUrl($organization, 'domains'), [
            'domain' => '  Example.COM  ',
            'include_subdomains' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('domain', 'example.com')
            ->assertJsonPath('status', OrganizationDomainStatus::PENDING->value);

        $domain = OrganizationDomain::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame('example.com', $domain->domain);
        $this->assertSame(0, OrganizationDomain::query()->where('organization_id', $organization->id)->where('is_primary', true)->count());

        $verifier = Mockery::mock(OrganizationDomainVerifier::class);
        $verifier->shouldReceive('hasVerificationRecord')->once()->withArgs(
            fn (OrganizationDomain $candidate): bool => $candidate->is($domain)
        )->andReturn(true);
        $this->app->instance(OrganizationDomainVerifier::class, $verifier);

        $this->postJson($this->organizationUrl($organization, "domains/{$domain->id}/verify"))
            ->assertOk()
            ->assertJsonPath('status', OrganizationDomainStatus::VERIFIED->value)
            ->assertJsonPath('verification_method', 'dns_txt');
        $this->postJson($this->organizationUrl($organization, "domains/{$domain->id}/primary"))
            ->assertOk()
            ->assertJsonPath('is_primary', true);

        $this->assertSame(1, OrganizationDomain::query()->where('organization_id', $organization->id)->where('is_primary', true)->count());
        $this->assertDatabaseHas('organization_audit_logs', [
            'organization_id' => $organization->id,
            'action' => AuditAction::ORGANIZATION_DOMAIN_VERIFIED->value,
        ]);
    }

    public function test_redemption_code_is_hashed_and_plaintext_is_returned_only_once(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->makeOrgUser($organization, OrganizationRole::ORG_OWNER);
        Sanctum::actingAs($owner);

        $response = $this->postJson($this->organizationUrl($organization, 'redemption-codes'), [
            'name' => 'Pilot team',
            'code_type' => 'pilot',
            'max_uses' => 25,
            'starts_at' => now()->toISOString(),
            'expires_at' => now()->addMonth()->toISOString(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.code_type', 'pilot');

        $plaintext = $response->json('one_time_code');
        $this->assertIsString($plaintext);
        $this->assertNotEmpty($plaintext);

        $code = OrganizationRedemptionCode::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame(hash('sha256', $plaintext), $code->code_hash);
        $this->assertNotSame($plaintext, $code->code_hash);

        $this->getJson($this->organizationUrl($organization, 'redemption-codes'))
            ->assertOk()
            ->assertJsonMissingPath('one_time_code')
            ->assertJsonMissingPath('data.0.code_hash');
    }

    public function test_expiry_marks_contract_and_active_memberships_expired(): void
    {
        $organization = Organization::factory()->create([
            'status' => OrganizationStatus::ACTIVE,
            'contract_end_at' => now()->subDay(),
        ]);
        $membership = OrganizationMembership::factory()->create([
            'organization_id' => $organization->id,
            'status' => MembershipStatus::ACTIVE,
        ]);
        $contract = OrganizationContract::create([
            'organization_id' => $organization->id,
            'status' => OrganizationStatus::ACTIVE,
            'package_code' => $organization->package_code,
            'seat_limit' => $organization->seat_limit,
            'starts_at' => now()->subYear(),
            'ends_at' => now()->subDay(),
            'pricing_config' => [],
        ]);

        $expiredCount = app(OrganizationService::class)->expirePastContracts();

        $this->assertSame(1, $expiredCount);
        $this->assertSame(OrganizationStatus::EXPIRED, $organization->fresh()->status);
        $this->assertSame(OrganizationStatus::EXPIRED, $contract->fresh()->status);
        $this->assertSame(MembershipStatus::EXPIRED, $membership->fresh()->status);
        $this->assertDatabaseHas('organization_audit_logs', [
            'organization_id' => $organization->id,
            'action' => AuditAction::ORGANIZATION_CONTRACT_EXPIRED->value,
        ]);
    }

    public function test_renewing_a_suspended_organization_keeps_it_suspended(): void
    {
        $organization = Organization::factory()->suspended()->create();
        Sanctum::actingAs($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $this->postJson("/api/v1/organizations/{$organization->id}/contracts/renew", [
            'package_code' => 'STANDARD',
            'seat_limit' => 10,
            'contract_start_at' => now()->subDay()->toISOString(),
            'contract_end_at' => now()->addYears(2)->toISOString(),
        ])
            ->assertOk()
            ->assertJsonPath('status', OrganizationStatus::SUSPENDED->value)
            ->assertJsonPath('current_contract.status', OrganizationStatus::SUSPENDED->value);

        $this->postJson("/api/v1/organizations/{$organization->id}/reactivate", ['reason' => 'Paid'])
            ->assertOk()
            ->assertJsonPath('status', OrganizationStatus::ACTIVE->value);
    }

    public function test_pending_organization_is_activated_when_its_contract_starts(): void
    {
        Sanctum::actingAs($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));
        $id = $this->postJson('/api/v1/organizations', [
            'name' => 'Future Start',
            'slug' => 'future-start',
            'organization_type' => 'CORPORATE',
            'package_code' => 'STANDARD',
            'seat_limit' => 10,
            'contract_start_at' => now()->addDays(2)->toISOString(),
            'contract_end_at' => now()->addYear()->toISOString(),
        ])->assertCreated()->assertJsonPath('status', OrganizationStatus::PENDING->value)->json('id');

        $service = app(OrganizationService::class);
        $this->assertSame(0, $service->activateStartedContracts());

        $this->travel(3)->days();
        $this->assertSame(1, $service->activateStartedContracts());

        $organization = Organization::findOrFail($id);
        $this->assertSame(OrganizationStatus::ACTIVE, $organization->status);
        $this->assertSame(OrganizationStatus::ACTIVE, $organization->currentContract->status);
        $this->assertDatabaseHas('organization_audit_logs', [
            'organization_id' => $id,
            'action' => AuditAction::ORGANIZATION_CONTRACT_ACTIVATED->value,
            'actor_type' => 'system',
            'actor_user_id' => null,
        ]);
    }

    public function test_pending_organization_whose_contract_already_ended_is_expired(): void
    {
        $organization = Organization::factory()->create([
            'status' => OrganizationStatus::PENDING,
            'contract_start_at' => now()->subMonth(),
            'contract_end_at' => now()->subDay(),
        ]);

        $this->assertSame(1, app(OrganizationService::class)->expirePastContracts());
        $this->assertSame(OrganizationStatus::EXPIRED, $organization->fresh()->status);
    }

    public function test_a_pending_domain_claim_does_not_block_the_real_owner(): void
    {
        $squatter = Organization::factory()->create();
        $owner = Organization::factory()->create();

        Sanctum::actingAs($this->makeOrgUser($squatter, OrganizationRole::ORG_ADMIN));
        $this->postJson($this->organizationUrl($squatter, 'domains'), ['domain' => 'acme.com'])->assertCreated();
        $this->postJson($this->organizationUrl($squatter, 'domains'), ['domain' => 'acme.com'])
            ->assertStatus(422)->assertJsonValidationErrors('domain');

        Sanctum::actingAs($this->makeOrgUser($owner, OrganizationRole::ORG_ADMIN));
        $domainId = $this->postJson($this->organizationUrl($owner, 'domains'), ['domain' => 'acme.com'])
            ->assertCreated()->json('id');

        $this->app->instance(OrganizationDomainVerifier::class, Mockery::mock(OrganizationDomainVerifier::class, [
            'hasVerificationRecord' => true,
        ]));

        $this->postJson($this->organizationUrl($owner, "domains/{$domainId}/verify"))
            ->assertOk()
            ->assertJsonPath('status', OrganizationDomainStatus::VERIFIED->value);

        $this->assertDatabaseMissing('organization_domains', ['organization_id' => $squatter->id, 'domain' => 'acme.com']);
        $this->assertDatabaseHas('organization_audit_logs', [
            'organization_id' => $squatter->id,
            'action' => AuditAction::ORGANIZATION_DOMAIN_REMOVED->value,
        ]);

        // Once verified, the domain is taken for everyone else.
        Sanctum::actingAs($this->makeOrgUser($squatter, OrganizationRole::ORG_ADMIN));
        $this->postJson($this->organizationUrl($squatter, 'domains'), ['domain' => 'acme.com'])
            ->assertStatus(422)->assertJsonValidationErrors('domain');
    }

    public function test_a_domain_verified_by_another_organization_cannot_be_verified_again(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        OrganizationDomain::factory()->create([
            'organization_id' => $first->id,
            'domain' => 'shared.com',
            'status' => OrganizationDomainStatus::VERIFIED,
            'verified_at' => now(),
        ]);
        $pending = OrganizationDomain::factory()->create([
            'organization_id' => $second->id,
            'domain' => 'shared.com',
            'status' => OrganizationDomainStatus::PENDING,
            'verified_at' => null,
        ]);

        Sanctum::actingAs($this->makeOrgUser($second, OrganizationRole::ORG_ADMIN));
        $this->postJson($this->organizationUrl($second, "domains/{$pending->id}/verify"))->assertStatus(409);
    }

    public function test_duplicate_entitlement_keys_are_rejected_by_validation(): void
    {
        Sanctum::actingAs($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $this->postJson('/api/v1/organizations', [
            'name' => 'Duplicate Keys',
            'slug' => 'duplicate-keys',
            'organization_type' => 'CORPORATE',
            'package_code' => 'CUSTOM',
            'seat_limit' => 10,
            'contract_start_at' => now()->subDay()->toISOString(),
            'contract_end_at' => now()->addYear()->toISOString(),
            'entitlements' => [['feature_key' => 'advanced_reports'], ['feature_key' => 'advanced_reports']],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['entitlements.0.feature_key', 'entitlements.1.feature_key']);
    }

    private function organizationUrl(Organization $organization, string $path): string
    {
        return "/api/v1/organizations/{$organization->id}/{$path}";
    }
}
