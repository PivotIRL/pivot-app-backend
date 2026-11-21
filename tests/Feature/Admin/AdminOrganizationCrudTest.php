<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Organization\Enums\AuditAction;
use App\Organization\Enums\AuditActorType;
use App\Organization\Enums\MembershipStatus;
use App\Organization\Enums\OrganizationStatus;
use App\Organization\Enums\PivotRole;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationAuditLog;
use App\Organization\Models\OrganizationMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Organization\Concerns\BuildsOrganizationActors;
use Tests\TestCase;

class AdminOrganizationCrudTest extends TestCase
{
    use BuildsOrganizationActors, RefreshDatabase;

    private const MANAGER_EMAIL = 'org-manager@example.test';

    protected function setUp(): void
    {
        parent::setUp();

        config(['organization.admin_dashboard.manager_emails' => [self::MANAGER_EMAIL]]);
    }

    private function makeAdmin(string $email = self::MANAGER_EMAIL, string $status = 'active'): Admin
    {
        return Admin::create([
            'name' => 'Organization Manager',
            'email' => $email,
            'password' => bcrypt('secret'),
            'status' => $status,
        ]);
    }

    private function actingAsManager(): Admin
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Acme Corp',
            'slug' => 'acme-corp',
            'organization_type' => 'CORPORATE',
            'account_type' => '',
            'package_code' => 'STANDARD',
            'seat_limit' => 25,
            'contract_start_at' => now()->subDay()->format('Y-m-d\TH:i'),
            'contract_end_at' => now()->addYear()->format('Y-m-d\TH:i'),
            'timezone' => 'Australia/Sydney',
            'country_code' => 'au',
            'pricing_config' => ['currency' => 'aud', 'unit_price' => '12.50', 'discount_percent' => '', 'minimum_seats' => '', 'platform_fee' => ''],
            'entitlements' => [['feature_key' => '', 'value_json' => '']],
        ], $overrides);
    }

    // Authorization

    public function test_guests_are_redirected_to_the_admin_login(): void
    {
        $this->get('/dashboard/organizations')->assertRedirect(route('admin.login'));
    }

    public function test_admins_not_on_the_allowlist_are_forbidden_and_do_not_see_the_menu(): void
    {
        $organization = Organization::factory()->create();
        $this->actingAs($this->makeAdmin('someone-else@example.test'), 'admin');

        $this->get('/dashboard/organizations')->assertForbidden();
        $this->get("/dashboard/organizations/{$organization->id}")->assertForbidden();
        $this->post('/dashboard/organizations', $this->validPayload())->assertForbidden();
        $this->delete("/dashboard/organizations/{$organization->id}", ['reason' => 'x', 'confirm_name' => $organization->name])->assertForbidden();
        $this->get('/dashboard')->assertDontSee(route('admin.organizations.index'));

        $this->assertNotSoftDeleted($organization);
        $this->assertDatabaseMissing('organizations', ['slug' => 'acme-corp']);
    }

    public function test_inactive_admins_on_the_allowlist_are_forbidden(): void
    {
        $this->actingAs($this->makeAdmin(status: 'inactive'), 'admin');

        $this->get('/dashboard/organizations')->assertForbidden();
    }

    public function test_an_empty_allowlist_denies_every_admin(): void
    {
        config(['organization.admin_dashboard.manager_emails' => []]);
        $this->actingAsManager();

        $this->get('/dashboard/organizations')->assertForbidden();
    }

    public function test_allowlisted_admin_sees_the_menu_item(): void
    {
        $this->actingAsManager();

        $this->get('/dashboard/organizations')->assertOk()->assertSee('Organizations')->assertSee(route('admin.organizations.create'));
    }

    // List and view

    public function test_index_lists_searches_and_filters_organizations(): void
    {
        $this->actingAsManager();
        $alpha = Organization::factory()->create(['name' => 'Alpha Industries']);
        $beta = Organization::factory()->suspended()->create(['name' => 'Beta Health']);
        $this->makeMember($alpha);

        $this->get('/dashboard/organizations')->assertOk()->assertSee('Alpha Industries')->assertSee('Beta Health')->assertSee('1 / 50');
        $this->get('/dashboard/organizations?search=alpha')->assertOk()->assertSee('Alpha Industries')->assertDontSee('Beta Health');
        $this->get('/dashboard/organizations?status=suspended')->assertOk()->assertSee('Beta Health')->assertDontSee('Alpha Industries');
        $this->get('/dashboard/organizations?status=bogus')->assertSessionHasErrors('status');
    }

    public function test_show_displays_details_contracts_and_activity(): void
    {
        $this->actingAsManager();
        $this->post('/dashboard/organizations', $this->validPayload())->assertSessionHasNoErrors();
        $organization = Organization::where('slug', 'acme-corp')->firstOrFail();

        $this->get("/dashboard/organizations/{$organization->id}")
            ->assertOk()
            ->assertSee('Acme Corp')
            ->assertSee('Australia/Sydney')
            ->assertSee('organization.created')
            ->assertSee('Organization Manager');
    }

    public function test_unknown_or_deleted_organizations_return_404(): void
    {
        $this->actingAsManager();
        $organization = Organization::factory()->create();
        $organization->delete();

        $this->get('/dashboard/organizations/999999')->assertNotFound();
        $this->get("/dashboard/organizations/{$organization->id}")->assertNotFound();
        $this->get('/dashboard/organizations/not-a-number')->assertNotFound();
    }

    // Create

    public function test_admin_creates_an_organization_through_the_existing_service(): void
    {
        $admin = $this->actingAsManager();

        $response = $this->post('/dashboard/organizations', $this->validPayload());

        $organization = Organization::where('slug', 'acme-corp')->firstOrFail();
        $response->assertRedirect(route('admin.organizations.show', $organization))->assertSessionHas('success');

        $this->assertSame(OrganizationStatus::ACTIVE, $organization->status);
        $this->assertSame('AU', $organization->country_code);
        $this->assertSame('Australia/Sydney', $organization->timezone);
        $this->assertNull($organization->created_by);
        $this->assertNull($organization->account_type);
        $this->assertSame(['currency' => 'AUD', 'unit_price' => '12.50'], $organization->pricing_config);
        $this->assertSame(1, $organization->contracts()->count());

        $log = OrganizationAuditLog::where('organization_id', $organization->id)
            ->where('action', AuditAction::ORGANIZATION_CREATED->value)
            ->firstOrFail();
        $this->assertSame(AuditActorType::ADMIN, $log->actor_type);
        $this->assertNull($log->actor_user_id);
        $this->assertSame($admin->id, $log->metadata['admin_id']);
        $this->assertSame('Organization Manager', $log->metadata['admin_name']);
    }

    public function test_future_contract_start_creates_a_pending_organization(): void
    {
        $this->actingAsManager();

        $this->post('/dashboard/organizations', $this->validPayload([
            'contract_start_at' => now()->addMonth()->format('Y-m-d\TH:i'),
        ]))->assertSessionHasNoErrors();

        $this->assertSame(OrganizationStatus::PENDING, Organization::where('slug', 'acme-corp')->firstOrFail()->status);
    }

    public function test_custom_package_requires_entitlements_and_stores_them(): void
    {
        $this->actingAsManager();

        $this->post('/dashboard/organizations', $this->validPayload(['package_code' => 'CUSTOM']))
            ->assertSessionHasErrors('entitlements');
        $this->assertDatabaseMissing('organizations', ['slug' => 'acme-corp']);

        $this->post('/dashboard/organizations', $this->validPayload([
            'package_code' => 'CUSTOM',
            'entitlements' => [['feature_key' => 'advanced_reports', 'value_json' => 'not json']],
        ]))->assertSessionHasErrors('entitlements.0.value_json');

        $this->post('/dashboard/organizations', $this->validPayload([
            'package_code' => 'CUSTOM',
            'entitlements' => [
                ['feature_key' => 'advanced_reports', 'value_json' => '{"limit": 10}'],
                ['feature_key' => 'sso', 'value_json' => ''],
            ],
        ]))->assertSessionHasNoErrors();

        $organization = Organization::where('slug', 'acme-corp')->firstOrFail();
        $this->assertSame(['advanced_reports', 'sso'], $organization->entitlements()->orderBy('feature_key')->pluck('feature_key')->all());
        $this->assertSame(['limit' => 10], $organization->entitlements()->where('feature_key', 'advanced_reports')->first()->value_json);
    }

    public function test_create_validates_input_with_the_existing_rules(): void
    {
        $this->actingAsManager();
        Organization::factory()->create(['slug' => 'taken']);

        $this->from('/dashboard/organizations/create')
            ->post('/dashboard/organizations', $this->validPayload([
                'name' => '',
                'slug' => 'taken',
                'seat_limit' => 0,
                'contract_end_at' => now()->subYear()->format('Y-m-d\TH:i'),
                'country_code' => 'AUS',
                'organization_type' => 'NOPE',
            ]))
            ->assertRedirect('/dashboard/organizations/create')
            ->assertSessionHasErrors(['name', 'slug', 'seat_limit', 'contract_end_at', 'country_code', 'organization_type']);

        $this->assertSame(1, Organization::count());
    }

    public function test_slug_of_a_deleted_organization_cannot_be_reused(): void
    {
        $this->actingAsManager();
        Organization::factory()->create(['slug' => 'acme-corp'])->delete();

        $this->post('/dashboard/organizations', $this->validPayload())->assertSessionHasErrors('slug');
    }

    // Edit

    public function test_edit_form_is_prefilled(): void
    {
        $this->actingAsManager();
        $organization = Organization::factory()->create(['name' => 'Prefilled Org', 'seat_limit' => 77]);

        $this->get("/dashboard/organizations/{$organization->id}/edit")
            ->assertOk()
            ->assertSee('value="Prefilled Org"', false)
            ->assertSee('value="77"', false);
    }

    public function test_admin_updates_an_organization_and_its_contract(): void
    {
        $admin = $this->actingAsManager();
        $this->post('/dashboard/organizations', $this->validPayload())->assertSessionHasNoErrors();
        $organization = Organization::where('slug', 'acme-corp')->firstOrFail();

        $this->put("/dashboard/organizations/{$organization->id}", $this->validPayload([
            'name' => 'Acme Corporation',
            'seat_limit' => 40,
            'contract_start_at' => $organization->contract_start_at->format('Y-m-d\TH:i'),
            'contract_end_at' => $organization->contract_end_at->format('Y-m-d\TH:i'),
        ]))->assertRedirect(route('admin.organizations.show', $organization))->assertSessionHasNoErrors();

        $organization->refresh();
        $this->assertSame('Acme Corporation', $organization->name);
        $this->assertSame(40, $organization->seat_limit);
        $this->assertSame(40, $organization->currentContract->seat_limit);

        $log = OrganizationAuditLog::where('organization_id', $organization->id)
            ->where('action', AuditAction::ORGANIZATION_UPDATED->value)
            ->firstOrFail();
        $this->assertSame(AuditActorType::ADMIN, $log->actor_type);
        $this->assertSame($admin->id, $log->metadata['admin_id']);
        $this->assertEqualsCanonicalizing(['name', 'seat_limit'], $log->metadata['changed_fields']);
    }

    public function test_saving_an_unchanged_form_changes_nothing(): void
    {
        $this->actingAsManager();
        $this->post('/dashboard/organizations', $this->validPayload())->assertSessionHasNoErrors();
        $organization = Organization::where('slug', 'acme-corp')->firstOrFail();
        $auditCount = OrganizationAuditLog::count();

        $this->put("/dashboard/organizations/{$organization->id}", $this->validPayload([
            'contract_start_at' => $organization->contract_start_at->format('Y-m-d\TH:i'),
            'contract_end_at' => $organization->contract_end_at->format('Y-m-d\TH:i'),
        ]))->assertSessionHas('success', 'No changes were made.');

        $this->assertSame($auditCount, OrganizationAuditLog::count());
    }

    public function test_editing_keeps_existing_volume_bands(): void
    {
        $this->actingAsManager();
        $bands = [['minimum_seats' => 100, 'unit_price' => 9]];
        $organization = Organization::factory()->create(['pricing_config' => ['currency' => 'AUD', 'volume_bands' => $bands]]);

        $this->put("/dashboard/organizations/{$organization->id}", $this->validPayload([
            'name' => $organization->name,
            'slug' => $organization->slug,
            'pricing_config' => ['currency' => 'USD'],
        ]))->assertSessionHasNoErrors();

        // jsonb does not keep key order, so compare values only.
        $this->assertEquals(['currency' => 'USD', 'volume_bands' => $bands], $organization->refresh()->pricing_config);
    }

    public function test_expired_organizations_cannot_be_edited(): void
    {
        $this->actingAsManager();
        $organization = Organization::factory()->expired()->create(['name' => 'Old Org']);

        $this->get("/dashboard/organizations/{$organization->id}/edit")->assertOk()->assertSee('cannot be edited here');

        $this->from("/dashboard/organizations/{$organization->id}/edit")
            ->put("/dashboard/organizations/{$organization->id}", $this->validPayload(['name' => 'Renamed', 'slug' => $organization->slug]))
            ->assertRedirect("/dashboard/organizations/{$organization->id}/edit")
            ->assertSessionHasErrors('organization');

        $this->assertSame('Old Org', $organization->refresh()->name);
    }

    public function test_update_slug_must_stay_unique(): void
    {
        $this->actingAsManager();
        Organization::factory()->create(['slug' => 'taken']);
        $organization = Organization::factory()->create();

        $this->put("/dashboard/organizations/{$organization->id}", $this->validPayload(['slug' => 'taken']))
            ->assertSessionHasErrors('slug');
    }

    // Delete

    public function test_delete_requires_a_reason_and_the_exact_name(): void
    {
        $this->actingAsManager();
        $organization = Organization::factory()->create(['name' => 'Delete Me Inc']);

        $this->delete("/dashboard/organizations/{$organization->id}", ['reason' => '', 'confirm_name' => 'Delete Me Inc'])
            ->assertSessionHasErrorsIn('deleteOrganization', 'reason');
        $this->delete("/dashboard/organizations/{$organization->id}", ['reason' => 'Contract ended', 'confirm_name' => 'delete me inc'])
            ->assertSessionHasErrorsIn('deleteOrganization', 'confirm_name');
        $this->delete("/dashboard/organizations/{$organization->id}", ['reason' => 'Contract ended'])
            ->assertSessionHasErrorsIn('deleteOrganization', 'confirm_name');

        $this->assertNotSoftDeleted($organization);
    }

    public function test_delete_soft_deletes_releases_seats_and_audits_the_admin(): void
    {
        $admin = $this->actingAsManager();
        $organization = Organization::factory()->create(['name' => 'Delete Me Inc']);
        $memberA = $this->makeMember($organization);
        $memberB = $this->makeMember($organization);

        $this->delete("/dashboard/organizations/{$organization->id}", ['reason' => 'Contract ended', 'confirm_name' => 'Delete Me Inc'])
            ->assertRedirect(route('admin.organizations.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted($organization);
        $this->assertSame(0, OrganizationMembership::where('organization_id', $organization->id)->where('status', MembershipStatus::ACTIVE)->count());

        foreach ([AuditAction::ORGANIZATION_REMOVED, AuditAction::MEMBERSHIPS_RELEASED] as $action) {
            $log = OrganizationAuditLog::where('organization_id', $organization->id)->where('action', $action->value)->firstOrFail();
            $this->assertSame(AuditActorType::ADMIN, $log->actor_type, $action->value);
            $this->assertSame($admin->id, $log->metadata['admin_id']);
        }
        $this->assertSame('Contract ended', OrganizationAuditLog::where('action', AuditAction::ORGANIZATION_REMOVED->value)->first()->metadata['reason']);
        $this->assertNotNull($memberA->fresh());
        $this->assertNotNull($memberB->fresh());
    }

    // Organization Dashboard compatibility

    public function test_organization_dashboard_audit_api_shows_admin_actor(): void
    {
        $this->actingAsManager();
        $this->post('/dashboard/organizations', $this->validPayload())->assertSessionHasNoErrors();
        $organization = Organization::where('slug', 'acme-corp')->firstOrFail();

        // Reset the web guard so the next request is a pure Organization Dashboard API call.
        $this->app['auth']->forgetGuards();
        $this->actingAsDashboard($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $this->getJson("/api/v1/organizations/{$organization->id}/audit-logs")
            ->assertOk()
            ->assertJsonFragment([
                'action' => AuditAction::ORGANIZATION_CREATED->value,
                'actor_type' => 'admin',
                'actor' => ['id' => null, 'name' => 'Organization Manager', 'email' => null],
            ]);
    }

    public function test_api_changes_by_users_are_still_recorded_as_user_actors(): void
    {
        $staff = $this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN);
        $this->actingAsDashboard($staff);

        $this->postJson('/api/v1/organizations', [
            'name' => 'Api Org',
            'slug' => 'api-org-'.Str::lower(Str::random(4)),
            'organization_type' => 'CORPORATE',
            'package_code' => 'STANDARD',
            'seat_limit' => 5,
            'contract_start_at' => now()->toISOString(),
            'contract_end_at' => now()->addYear()->toISOString(),
        ])->assertCreated();

        $log = OrganizationAuditLog::where('action', AuditAction::ORGANIZATION_CREATED->value)->firstOrFail();
        $this->assertSame(AuditActorType::PIVOT_STAFF, $log->actor_type);
        $this->assertSame($staff->id, $log->actor_user_id);
        $this->assertArrayNotHasKey('admin_id', $log->metadata ?? []);
    }
}
