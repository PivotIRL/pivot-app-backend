<?php

namespace Tests\Feature\Organization;

use App\Organization\Enums\AuditAction;
use App\Organization\Enums\MembershipStatus;
use App\Organization\Enums\OrganizationRole;
use App\Organization\Mail\OrganizationMemberInvitationMail;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationAuditLog;
use App\Organization\Models\OrganizationMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Organization\Concerns\BuildsOrganizationActors;
use Tests\TestCase;

/**
 * POST …/members/invitation-link (dashboard), GET /{user}/{organization}/{membership} (page),
 * POST /organization/invitation-link/accept (mobile).
 */
class MemberInvitationLinkTest extends TestCase
{
    use BuildsOrganizationActors, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config([
            'organization.member_invitation.base_url' => 'https://staging-api.pivotirl.com.au',
            'organization.member_invitation.app_url' => 'pivot://organization/invitation',
        ]);
    }

    public function test_admin_sends_a_link_in_the_requested_format_and_it_is_emailed(): void
    {
        $organization = Organization::factory()->create();
        $invitee = $this->makeUser();
        $this->actingAsDashboard($this->makeOrgUser($organization, OrganizationRole::ORG_ADMIN));

        $response = $this->postJson($this->url($organization, 'members/invitation-link'), ['email' => strtoupper($invitee->email)])
            ->assertCreated()
            ->assertJsonPath('data.status', 'invited')
            ->assertJsonPath('data.user.id', $invitee->id)
            ->assertJsonPath('email_sent', true);

        $membership = OrganizationMembership::where('user_id', $invitee->id)->sole();
        $link = $response->json('invitation_link');

        $this->assertMatchesRegularExpression(
            "#^https://staging-api\.pivotirl\.com\.au/{$invitee->id}/{$organization->id}/{$membership->id}\?expires=\d+&signature=[0-9a-f]{64}$#",
            $link,
        );
        $this->assertSame(MembershipStatus::INVITED, $membership->status);
        Mail::assertSent(OrganizationMemberInvitationMail::class, fn ($mail) => $mail->hasTo($invitee->email) && $mail->link === $link);
        $this->assertTrue(OrganizationAuditLog::where('action', AuditAction::MEMBER_INVITATION_LINK_SENT)->exists());
    }

    public function test_sending_rules(): void
    {
        $organization = Organization::factory()->create();
        $active = $this->makeMember($organization);
        $suspended = $this->makeMember($organization);
        OrganizationMembership::where('user_id', $suspended->id)->update(['status' => MembershipStatus::SUSPENDED]);
        $former = $this->makeUser();
        OrganizationMembership::factory()->deactivated()->create(['organization_id' => $organization->id, 'user_id' => $former->id]);
        $this->actingAsDashboard($this->makeOrgUser($organization, OrganizationRole::ORG_OWNER));

        $this->postJson($this->url($organization, 'members/invitation-link'), ['email' => 'nobody@example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email']);
        $this->postJson($this->url($organization, 'members/invitation-link'), ['email' => $active->email])->assertStatus(409);
        $this->postJson($this->url($organization, 'members/invitation-link'), ['email' => $suspended->email])->assertStatus(409);
        $this->postJson($this->url($organization, 'members/invitation-link'), ['email' => $former->email, 'expires_in_days' => 31])
            ->assertUnprocessable()->assertJsonValidationErrors(['expires_in_days']);

        // A former member is invited back on the same row; resending keeps the row.
        $this->postJson($this->url($organization, 'members/invitation-link'), ['email' => $former->email])
            ->assertCreated()->assertJsonPath('data.status', 'invited');
        $this->postJson($this->url($organization, 'members/invitation-link'), ['email' => $former->email])->assertCreated();
        $this->assertSame(1, OrganizationMembership::where('user_id', $former->id)->count());
    }

    public function test_only_users_who_manage_invitations_can_send(): void
    {
        $organization = Organization::factory()->create();
        $invitee = $this->makeUser();
        $this->actingAsDashboard($this->makeOrgUser($organization, OrganizationRole::ORG_ANALYST));

        $this->postJson($this->url($organization, 'members/invitation-link'), ['email' => $invitee->email])->assertForbidden();
        $this->assertFalse(OrganizationMembership::where('user_id', $invitee->id)->exists());
    }

    public function test_the_link_page_shows_the_invitation_and_never_changes_data(): void
    {
        [$organization, $invitee, $link] = $this->sendLink();

        $response = $this->get($this->relative($link))
            ->assertOk()
            ->assertSee("Join {$organization->name} on Pivot")
            ->assertSee('pivot://organization/invitation?link=', false);

        $this->assertStringContainsString(urlencode($link), html_entity_decode($response->getContent()));
        $this->assertSame(MembershipStatus::INVITED, OrganizationMembership::where('user_id', $invitee->id)->sole()->status);
    }

    public function test_tampered_or_expired_links_are_rejected_on_the_page(): void
    {
        [, $invitee, $link] = $this->sendLink();
        $path = $this->relative($link);

        // Changing any ID breaks the signature.
        $tampered = preg_replace('#^/'.$invitee->id.'/#', '/'.($invitee->id + 1).'/', $path);
        $this->get($tampered)->assertNotFound()->assertSee('no longer valid');
        $this->get(strtok($path, '?'))->assertNotFound();

        $this->travel(8)->days();
        $this->get($path)->assertStatus(410)->assertSee('expired');
    }

    public function test_the_invited_user_accepts_in_the_app_and_takes_a_seat(): void
    {
        [$organization, $invitee, $link] = $this->sendLink();

        Sanctum::actingAs($invitee);
        $this->postJson('/api/v1/organization/invitation-link/accept', ['link' => $link])
            ->assertCreated()
            ->assertJsonPath('join_status', 'JOINED');

        $membership = OrganizationMembership::where('user_id', $invitee->id)->sole();
        $this->assertSame(MembershipStatus::ACTIVE, $membership->status);
        $this->assertSame('invitation', $membership->joined_via->value);

        // Accepting again is harmless; the page now says they already joined.
        $this->postJson('/api/v1/organization/invitation-link/accept', ['link' => $link])
            ->assertOk()->assertJsonPath('join_status', 'ALREADY_MEMBER');
        $this->get($this->relative($link))->assertOk()->assertSee('already a member');
    }

    public function test_accepting_rejects_other_users_bad_links_and_cancelled_invitations(): void
    {
        [$organization, $invitee, $link] = $this->sendLink();

        Sanctum::actingAs($this->makeUser());
        $this->postJson('/api/v1/organization/invitation-link/accept', ['link' => $link])
            ->assertForbidden()->assertJsonPath('join_status', 'INVITATION_EMAIL_MISMATCH');

        Sanctum::actingAs($invitee);
        $this->postJson('/api/v1/organization/invitation-link/accept', ['link' => $link.'0'])
            ->assertNotFound()->assertJsonPath('join_status', 'INVALID_INVITATION');
        $this->postJson('/api/v1/organization/invitation-link/accept', ['link' => 'https://example.com/not/a/link'])
            ->assertNotFound()->assertJsonPath('join_status', 'INVALID_INVITATION');
        $this->postJson('/api/v1/organization/invitation-link/accept', [])->assertUnprocessable();

        // Deactivating the invited member cancels the link.
        OrganizationMembership::where('user_id', $invitee->id)->update(['status' => MembershipStatus::DEACTIVATED]);
        $this->postJson('/api/v1/organization/invitation-link/accept', ['link' => $link])
            ->assertNotFound()->assertJsonPath('join_status', 'INVALID_INVITATION');
    }

    public function test_expired_link_and_seat_limit_are_reported(): void
    {
        [$organization, $invitee, $link] = $this->sendLink();
        Sanctum::actingAs($invitee);

        $organization->update(['seat_limit' => OrganizationMembership::where('organization_id', $organization->id)->where('status', 'active')->count()]);
        $this->postJson('/api/v1/organization/invitation-link/accept', ['link' => $link])
            ->assertStatus(409)->assertJsonPath('join_status', 'SEAT_LIMIT_REACHED');

        $this->travel(8)->days();
        $this->postJson('/api/v1/organization/invitation-link/accept', ['link' => $link])
            ->assertStatus(409)->assertJsonPath('join_status', 'INVITATION_EXPIRED');
    }

    /**
     * @return array{0: Organization, 1: \App\Models\User, 2: string}
     */
    private function sendLink(): array
    {
        $organization = Organization::factory()->create();
        $invitee = $this->makeUser();
        $this->actingAsDashboard($this->makeOrgUser($organization, OrganizationRole::ORG_OWNER));

        $link = $this->postJson($this->url($organization, 'members/invitation-link'), ['email' => $invitee->email])
            ->assertCreated()
            ->json('invitation_link');

        return [$organization, $invitee, $link];
    }

    // The test app serves on its own host; the signature is host-independent.
    private function relative(string $link): string
    {
        return substr($link, strlen('https://staging-api.pivotirl.com.au'));
    }

    private function url(Organization $organization, string $path): string
    {
        return "/api/v1/organizations/{$organization->id}/{$path}";
    }
}
