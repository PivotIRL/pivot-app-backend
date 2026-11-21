<?php

namespace Tests\Feature\Organization;

use App\Organization\Enums\MembershipStatus;
use App\Organization\Enums\OrganizationRole;
use App\Organization\Enums\PivotRole;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationDomain;
use App\Organization\Models\OrganizationMembership;
use App\Organization\Repositories\Contracts\OrganizationDomainRepositoryInterface;
use App\Organization\Services\OrganizationDomainService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Organization\Concerns\BuildsOrganizationActors;
use Tests\TestCase;

/**
 * Status codes and messages for Organization API failures.
 * Every error body is exactly { "message": ... } (plus "errors" for validation), with no internal details.
 */
class OrganizationErrorResponsesTest extends TestCase
{
    use BuildsOrganizationActors, RefreshDatabase;

    private const MISSING_ID = 999999;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
    }

    // Authentication runs before any ID is looked up.

    public static function protectedUris(): array
    {
        return [
            'missing organization' => ['GET', '/api/v1/organizations/'.self::MISSING_ID],
            'missing organization child list' => ['GET', '/api/v1/organizations/'.self::MISSING_ID.'/members'],
            'missing child' => ['POST', '/api/v1/organizations/{organization}/domains/'.self::MISSING_ID.'/verify'],
            'existing organization' => ['GET', '/api/v1/organizations/{organization}/roles'],
            'staff update of missing organization' => ['PUT', '/api/v1/organizations/'.self::MISSING_ID],
        ];
    }

    #[DataProvider('protectedUris')]
    public function test_missing_token_returns_401_even_for_ids_that_do_not_exist(string $method, string $uri): void
    {
        $this->json($method, $this->resolve($uri))
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    #[DataProvider('protectedUris')]
    public function test_invalid_token_returns_401_even_for_ids_that_do_not_exist(string $method, string $uri): void
    {
        $this->withToken('1|not-a-real-token')
            ->json($method, $this->resolve($uri))
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_mobile_token_on_a_missing_organization_returns_401(): void
    {
        $owner = $this->makeOrgUser($this->organization, OrganizationRole::ORG_OWNER);
        $token = $owner->createToken('mobile')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/organizations/'.self::MISSING_ID)
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_disabled_dashboard_account_gets_403_before_the_id_is_looked_up(): void
    {
        $account = $this->actingAsDashboard($this->makeOrgUser($this->organization, OrganizationRole::ORG_OWNER));
        $account->forceFill(['disabled_at' => now()])->save();

        $this->getJson('/api/v1/organizations/'.self::MISSING_ID)
            ->assertForbidden()
            ->assertExactJson(['message' => 'This dashboard account has been disabled.']);
    }

    public function test_mobile_join_endpoints_require_a_token(): void
    {
        $this->postJson('/api/v1/organization/join/code', ['code' => 'PIVOT-X'])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    // Missing resources name what was not found.

    public function test_missing_organization_returns_organization_not_found(): void
    {
        $this->actingAsDashboard($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $this->assertNotFoundMessage($this->getJson('/api/v1/organizations/'.self::MISSING_ID), 'Organization not found.');
        $this->assertNotFoundMessage($this->getJson('/api/v1/organizations/'.self::MISSING_ID.'/members'), 'Organization not found.');
        $this->assertNotFoundMessage(
            $this->putJson('/api/v1/organizations/'.self::MISSING_ID, ['name' => 'Renamed']),
            'Organization not found.',
        );
    }

    public function test_removed_organization_returns_organization_not_found(): void
    {
        $removed = Organization::factory()->create();
        $removed->delete();
        $this->actingAsDashboard($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $this->assertNotFoundMessage($this->getJson("/api/v1/organizations/{$removed->id}"), 'Organization not found.');
    }

    public static function missingChildren(): array
    {
        return [
            'domain' => ['POST', 'domains/%d/verify', [], 'Domain not found.'],
            'domain delete' => ['DELETE', 'domains/%d', [], 'Domain not found.'],
            'membership' => ['POST', 'members/%d/suspend', ['reason' => 'x'], 'Membership not found.'],
            'invitation' => ['DELETE', 'invitations/%d', [], 'Invitation not found.'],
            'redemption code' => ['DELETE', 'redemption-codes/%d', [], 'Redemption code not found.'],
            'role assignment' => ['DELETE', 'roles/%d', [], 'Role assignment not found.'],
        ];
    }

    #[DataProvider('missingChildren')]
    public function test_missing_child_resource_names_the_resource(string $method, string $path, array $body, string $message): void
    {
        $this->actingAsDashboard($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $this->assertNotFoundMessage(
            $this->json($method, $this->url($this->organization, sprintf($path, self::MISSING_ID)), $body),
            $message,
        );
    }

    public function test_child_of_another_organization_gets_the_same_404_as_a_missing_one(): void
    {
        $other = Organization::factory()->create();
        $foreignDomain = OrganizationDomain::factory()->create(['organization_id' => $other->id]);
        $this->actingAsDashboard($this->makeOrgUser($this->organization, OrganizationRole::ORG_OWNER));

        $this->assertNotFoundMessage(
            $this->deleteJson($this->url($this->organization, "domains/{$foreignDomain->id}")),
            'Domain not found.',
        );
        $this->assertNotFoundMessage(
            $this->deleteJson($this->url($this->organization, 'domains/'.self::MISSING_ID)),
            'Domain not found.',
        );
        $this->assertNotNull($foreignDomain->fresh());
    }

    // Tenant visibility: unrelated users cannot tell an existing organization from a missing one.

    public function test_unrelated_admin_gets_404_for_an_existing_organization(): void
    {
        $other = Organization::factory()->create();
        $this->actingAsDashboard($this->makeOrgUser($this->organization, OrganizationRole::ORG_ADMIN));

        $existing = $this->getJson("/api/v1/organizations/{$other->id}/members");
        $missing = $this->getJson('/api/v1/organizations/'.self::MISSING_ID.'/members');

        $this->assertNotFoundMessage($existing, 'Organization not found.');
        $this->assertNotFoundMessage($missing, 'Organization not found.');
        $this->assertSame($missing->getContent(), $existing->getContent());
    }

    public function test_user_linked_to_the_organization_keeps_the_403(): void
    {
        $other = Organization::factory()->create();
        $admin = $this->makeOrgUser($this->organization, OrganizationRole::ORG_ADMIN);
        OrganizationMembership::factory()->create([
            'organization_id' => $other->id,
            'user_id' => $admin->id,
            'status' => MembershipStatus::SUSPENDED,
        ]);
        $this->actingAsDashboard($admin);

        $this->getJson("/api/v1/organizations/{$other->id}/members")
            ->assertForbidden()
            ->assertExactJson(['message' => 'You do not have access to this organization.']);
    }

    public function test_unrelated_user_cannot_probe_organizations_through_the_organization_id_input(): void
    {
        $other = Organization::factory()->create();
        $this->actingAsDashboard($this->makeOrgUser($this->organization, OrganizationRole::ORG_ADMIN));

        $this->assertNotFoundMessage(
            $this->getJson("/api/v1/organizations/{$other->id}/roles?organization_id={$this->organization->id}"),
            'Organization not found.',
        );
    }

    public function test_contract_routes_hide_unrelated_organizations_from_non_staff(): void
    {
        $other = Organization::factory()->create();
        $this->actingAsDashboard($this->makeOrgUser($this->organization, OrganizationRole::ORG_OWNER));

        $this->assertNotFoundMessage(
            $this->putJson("/api/v1/organizations/{$other->id}", ['name' => 'Renamed']),
            'Organization not found.',
        );
        $this->assertNotFoundMessage(
            $this->postJson("/api/v1/organizations/{$other->id}/contracts/renew", []),
            'Organization not found.',
        );

        // Their own organization: it exists for them, so the policy's 403 applies.
        $this->putJson("/api/v1/organizations/{$this->organization->id}", ['name' => 'Renamed'])
            ->assertForbidden()
            ->assertExactJson(['message' => "You do not have permission to manage this organization's contract."]);
    }

    // Malformed IDs and unknown routes.

    public static function malformedUris(): array
    {
        return [
            'non-numeric organization' => ['GET', '/api/v1/organizations/abc/roles'],
            'uuid organization' => ['GET', '/api/v1/organizations/9d3f6b1e-1c2a-4f5b-8a7d-0e1f2a3b4c5d/roles'],
            'oversized organization' => ['GET', '/api/v1/organizations/99999999999999999999'],
            'non-numeric child' => ['POST', '/api/v1/organizations/{organization}/domains/abc/verify'],
            'oversized child' => ['DELETE', '/api/v1/organizations/{organization}/roles/99999999999999999999'],
            'unknown path' => ['GET', '/api/v1/organizations/{organization}/does-not-exist'],
        ];
    }

    #[DataProvider('malformedUris')]
    public function test_malformed_ids_and_unknown_paths_are_genuine_404s(string $method, string $uri): void
    {
        $this->actingAsDashboard($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $this->assertNotFoundMessage($this->json($method, $this->resolve($uri)), 'Requested endpoint was not found.');
    }

    public function test_nineteen_digit_id_within_bigint_range_is_accepted_and_reported_as_not_found(): void
    {
        $this->actingAsDashboard($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $this->assertNotFoundMessage($this->getJson('/api/v1/organizations/1000000000000000000'), 'Organization not found.');
        $this->assertNotFoundMessage($this->getJson('/api/v1/organizations/9223372036854775807'), 'Organization not found.');
    }

    // The only request in this test: Postgres aborts the test's wrapping transaction after the out-of-range error.
    // Reporting is faked because the database log channel cannot write inside that aborted test transaction;
    // rendering still goes through the real exception handler.
    public function test_nineteen_digit_id_above_bigint_range_returns_404_without_database_details(): void
    {
        Exceptions::fake([QueryException::class]);
        $this->actingAsDashboard($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $response = $this->getJson('/api/v1/organizations/9999999999999999999');

        $this->assertNotFoundMessage($response, 'Resource not found.');
        $this->assertNoInternalDetails($response);
        Exceptions::assertReported(fn (QueryException $e) => $e->getCode() === '22003');
    }

    public function test_wrong_http_method_returns_405(): void
    {
        $this->actingAsDashboard($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $this->patchJson("/api/v1/organizations/{$this->organization->id}/domains")
            ->assertStatus(405)
            ->assertExactJson(['message' => 'HTTP method is not allowed for this endpoint.']);
    }

    // Database and unexpected failures.

    public function test_duplicate_record_returns_409_without_database_details(): void
    {
        $this->mock(OrganizationDomainRepositoryInterface::class)
            ->shouldReceive('create')
            ->andThrow(new UniqueConstraintViolationException(
                'pgsql',
                'insert into "organization_domains" ...',
                [],
                new PDOException('SQLSTATE[23505]: Unique violation: secret detail'),
            ));
        $this->actingAsDashboard($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $response = $this->postJson($this->url($this->organization, 'domains'), ['domain' => 'race-condition.example.com'])
            ->assertStatus(409)
            ->assertExactJson(['message' => 'This record already exists.']);

        $this->assertNoInternalDetails($response);
    }

    public function test_other_database_failures_return_500_not_422(): void
    {
        config(['app.debug' => true]);
        $this->mock(OrganizationDomainService::class)
            ->shouldReceive('list')
            ->andThrow(new QueryException('pgsql', 'select * from "organization_domains"', [], new PDOException('SQLSTATE[08006]: connection failure')));
        $this->actingAsDashboard($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $response = $this->getJson($this->url($this->organization, 'domains'))
            ->assertStatus(500)
            ->assertExactJson(['message' => 'Unexpected server error. Please try again later.']);

        $this->assertNoInternalDetails($response);
    }

    public function test_unexpected_exception_returns_a_generic_500_even_in_debug_mode(): void
    {
        config(['app.debug' => true]);
        $this->mock(OrganizationDomainService::class)
            ->shouldReceive('list')
            ->andThrow(new RuntimeException('secret failure in /var/www/app/Secret.php'));
        $this->actingAsDashboard($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $response = $this->getJson($this->url($this->organization, 'domains'))
            ->assertStatus(500)
            ->assertExactJson(['message' => 'Unexpected server error. Please try again later.']);

        $this->assertNoInternalDetails($response);
    }

    // Existing responses that must not change.

    public function test_validation_errors_keep_the_existing_format(): void
    {
        $this->actingAsDashboard($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));

        $this->postJson($this->url($this->organization, 'domains'), [])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Invalid data provided.',
                'errors' => ['domain' => ['The domain field is required.']],
            ]);
    }

    public function test_business_rule_conflicts_keep_409(): void
    {
        $this->actingAsDashboard($this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN));
        $domain = OrganizationDomain::factory()->create(['organization_id' => $this->organization->id]);

        $this->postJson($this->url($this->organization, "domains/{$domain->id}/verify"))
            ->assertStatus(409)
            ->assertExactJson(['message' => 'This domain has already been verified.']);
    }

    private function assertNotFoundMessage(TestResponse $response, string $message): void
    {
        $response->assertNotFound()->assertExactJson(['message' => $message]);
    }

    private function assertNoInternalDetails(TestResponse $response): void
    {
        foreach (['SQLSTATE', 'secret', '/var/www', 'select ', 'insert '] as $fragment) {
            $this->assertStringNotContainsString($fragment, $response->getContent());
        }
    }

    private function resolve(string $uri): string
    {
        return str_replace('{organization}', (string) $this->organization->id, $uri);
    }

    private function url(Organization $organization, string $path): string
    {
        return "/api/v1/organizations/{$organization->id}/{$path}";
    }
}
