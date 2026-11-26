<?php

namespace Tests\Feature\Organization;

use App\Organization\Enums\MembershipStatus;
use App\Organization\Enums\OrganizationRole;
use App\Organization\Enums\PivotRole;
use App\Organization\Mail\OrganizationInvitationMail;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationInvitation;
use App\Organization\Models\OrganizationMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Organization\Concerns\BuildsOrganizationActors;
use Tests\TestCase;

/**
 * Individual invitation link: POST …/invitations (dashboard), GET /organization/join (page),
 * GET /organization/invitations/preview and POST /organization/invitations/accept (mobile).
 */
class OrganizationInvitationLinkTest extends TestCase
{
    use BuildsOrganizationActors, RefreshDatabase;

    private const ACCEPT = '/api/v1/organization/invitations/accept';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config([
            'organization.invitation.base_url' => 'https://pivotirl.com.au',
            'organization.invitation.app_url' => 'pivot://organization/join',
        ]);
    }

    public function test_creating_an_invitation_emails_the_link_and_code(): void
    {
        [, $response] = $this->invite('new.person@acme.com');

        $token = $response->json('one_time_token');
        $code = $response->json('invitation_code');
        $link = "https://pivotirl.com.au/organization/join?token={$token}&code={$code}";

        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{6}$/', $code);
        $this->assertSame($link, $response->json('invitation_link'));
        $this->assertTrue($response->json('email_sent'));
        $this->assertSame(OrganizationInvitation::hashCode($code), OrganizationInvitation::sole()->code_hash);
        $this->assertArrayNotHasKey('code_hash', $response->json('data'));
        Mail::assertSent(OrganizationInvitationMail::class, fn ($mail) => $mail->hasTo('new.person@acme.com')
            && $mail->link === $link && str_contains($mail->render(), $code));
    }

    public function test_page_and_preview_describe_the_invitation_without_changing_it(): void
    {
        [$organization, $response] = $this->invite('alice@acme.com');
        $token = $response->json('one_time_token');
        $code = $response->json('invitation_code');

        $this->get("/organization/join?token={$token}&code={$code}")
            ->assertOk()
            ->assertSee("Join {$organization->name} on Pivot")
            ->assertSee($code)
            ->assertSee("pivot://organization/join?token={$token}&code={$code}");
        $this->getJson("/api/v1/organization/invitations/preview?token={$token}&code={$code}")
            ->assertOk()
            ->assertJsonPath('data.organization_name', $organization->name)
            ->assertJsonPath('data.email', 'alice@acme.com');

        $wrong = $this->otherCode($code);
        $this->get("/organization/join?token={$token}&code={$wrong}")->assertNotFound()->assertSee('no longer valid');
        $this->get('/organization/join?token[]=x')->assertNotFound();
        $this->get('/organization/join')->assertNotFound();
        $this->getJson('/api/v1/organization/invitations/preview?token=nope')
            ->assertNotFound()->assertJsonPath('join_status', 'INVALID_INVITATION');

        $this->assertSame('pending', OrganizationInvitation::sole()->status->value);

        $this->travel(8)->days();
        $this->get("/organization/join?token={$token}&code={$code}")->assertStatus(410)->assertSee('expired');
        $this->getJson("/api/v1/organization/invitations/preview?token={$token}")
            ->assertStatus(409)->assertJsonPath('join_status', 'INVITATION_EXPIRED');
    }

    public function test_a_person_who_registers_after_the_invitation_accepts_with_the_link(): void
    {
        [, $response] = $this->invite('late@acme.com');
        $token = $response->json('one_time_token');
        $code = $response->json('invitation_code');

        $user = $this->makeUser();
        $user->update(['email' => 'Late@Acme.com']);
        Sanctum::actingAs($user);

        $this->postJson(self::ACCEPT, ['token' => $token, 'code' => $this->otherCode($code)])->assertNotFound();
        $this->postJson(self::ACCEPT, ['token' => $token, 'code' => $code])->assertCreated()->assertJsonPath('join_status', 'JOINED');
        $this->postJson(self::ACCEPT, ['token' => $token, 'code' => $code])->assertOk()->assertJsonPath('join_status', 'ALREADY_MEMBER');
        $this->get("/organization/join?token={$token}&code={$code}")->assertOk()->assertSee('already a member');
    }

    public function test_the_invited_user_can_type_the_code_alone_and_others_cannot(): void
    {
        $invitee = $this->makeUser();
        [, $response] = $this->invite($invitee->email);
        $code = $response->json('invitation_code');

        Sanctum::actingAs($this->makeUser());
        $this->postJson(self::ACCEPT, ['code' => $code])->assertNotFound()->assertJsonPath('join_status', 'INVALID_INVITATION');

        Sanctum::actingAs($invitee);
        $this->postJson(self::ACCEPT, [])->assertUnprocessable()->assertJsonValidationErrors(['token', 'code']);
        $this->postJson(self::ACCEPT, ['code' => '12345'])->assertUnprocessable()->assertJsonValidationErrors(['code']);
        $this->postJson(self::ACCEPT, ['code' => strtolower($code)])->assertCreated()->assertJsonPath('join_status', 'JOINED');
        $this->postJson(self::ACCEPT, ['code' => $code])->assertOk()->assertJsonPath('join_status', 'ALREADY_MEMBER');
    }

    public function test_five_wrong_codes_lock_the_code_but_not_the_link(): void
    {
        $invitee = $this->makeUser();
        [, $response] = $this->invite($invitee->email);
        $code = $response->json('invitation_code');
        Sanctum::actingAs($invitee);

        for ($i = 0; $i < OrganizationInvitation::MAX_CODE_ATTEMPTS; $i++) {
            $this->postJson(self::ACCEPT, ['code' => $this->otherCode($code)])->assertNotFound();
        }

        $this->assertSame(5, OrganizationInvitation::sole()->code_failed_attempts);
        $this->postJson(self::ACCEPT, ['code' => $code])->assertNotFound();
        $this->postJson(self::ACCEPT, ['token' => $response->json('one_time_token'), 'code' => $code])->assertCreated();
    }

    public function test_deactivating_an_invited_member_cancels_the_link(): void
    {
        $invitee = $this->makeUser();
        [$organization, $response] = $this->invite($invitee->email);
        $membership = OrganizationMembership::where('user_id', $invitee->id)->sole();

        $this->deleteJson("/api/v1/organizations/{$organization->id}/members/{$membership->id}", ['reason' => 'Not joining'])->assertOk();
        $this->assertSame('revoked', OrganizationInvitation::sole()->status->value);

        Sanctum::actingAs($invitee);
        $this->postJson(self::ACCEPT, ['token' => $response->json('one_time_token')])->assertNotFound();
    }

    public function test_the_list_shows_the_link_only_while_it_can_be_used(): void
    {
        [$organization, $created] = $this->invite('alice@acme.com');
        $url = "/api/v1/organizations/{$organization->id}/invitations";

        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.0.invitation_link', $created->json('invitation_link'))
            ->assertJsonMissingPath('data.0.token_encrypted')
            ->assertJsonMissingPath('data.0.code_encrypted');
        $this->assertNotSame($created->json('one_time_token'), DB::table('organization_invitations')->value('token_encrypted'));

        $this->travel(8)->days();
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.invitation_link', null);
        $this->travelBack();

        $this->deleteJson("{$url}/{$created->json('data.id')}")->assertOk()->assertJsonPath('invitation_link', null);
    }

    public function test_admins_do_not_see_links_for_roles_they_cannot_assign(): void
    {
        [$organization] = $this->invite('owner@acme.com', OrganizationRole::ORG_OWNER);
        $this->actingAsDashboard($this->makeOrgUser($organization, OrganizationRole::ORG_ADMIN));

        $this->getJson("/api/v1/organizations/{$organization->id}/invitations")
            ->assertOk()->assertJsonPath('data.0.invitation_link', null);
    }

    public function test_suspended_members_are_not_invited(): void
    {
        $organization = Organization::factory()->create();
        $member = $this->makeMember($organization);
        OrganizationMembership::where('user_id', $member->id)->update(['status' => MembershipStatus::SUSPENDED]);
        $this->actingAsDashboard($this->makeOrgUser($organization, OrganizationRole::ORG_OWNER));

        $this->postJson("/api/v1/organizations/{$organization->id}/invitations", ['email' => $member->email])->assertStatus(409);
        Mail::assertNothingSent();
    }

    /**
     * @return array{0: Organization, 1: \Illuminate\Testing\TestResponse}
     */
    private function invite(string $email, OrganizationRole $role = OrganizationRole::ORG_MEMBER): array
    {
        $organization = Organization::factory()->create();
        // Only Pivot staff can invite an owner.
        $this->actingAsDashboard($role === OrganizationRole::ORG_OWNER
            ? $this->makeStaff(PivotRole::PIVOT_SUPER_ADMIN)
            : $this->makeOrgUser($organization, OrganizationRole::ORG_OWNER));

        $response = $this->postJson("/api/v1/organizations/{$organization->id}/invitations", ['email' => $email, 'role' => $role->value])
            ->assertCreated();

        return [$organization, $response];
    }

    private function otherCode(string $code): string
    {
        return $code === 'AAAAAA' ? 'BBBBBB' : 'AAAAAA';
    }
}
