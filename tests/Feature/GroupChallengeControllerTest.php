<?php

namespace Tests\Feature;

use App\Events\ChallengeInviteReceived;
use App\Events\GroupChallengeCancelled;
use App\Events\GroupChallengeCompleted;
use App\Events\GroupChallengeLobbyUpdated;
use App\Events\GroupChallengeParticipantLeft;
use App\Events\GroupChallengePaused;
use App\Events\GroupChallengeResumed;
use App\Events\GroupChallengeStarted;
use App\Models\GroupChallengeParticipant;
use App\Models\GroupChallengeSession;
use App\Models\EmotionLogs;
use App\Models\TeamConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GroupChallengeControllerTest extends TestCase
{
    use RefreshDatabase;

    private function connectTeammates(User $host, User $teammate): void
    {
        TeamConnection::create(['user_id' => $host->id, 'connected_user_id' => $teammate->id]);
        TeamConnection::create(['user_id' => $teammate->id, 'connected_user_id' => $host->id]);
    }

    /**
     * The stock UserFactory sets `email_verified_at`, a column the users
     * migration in this repo never added, so build users directly instead.
     */
    private function makeUser(string $status): User
    {
        return User::create([
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => bcrypt('password'),
            'status' => $status,
        ]);
    }

    private function createInProgressSession(User $host, User $teammate): GroupChallengeSession
    {
        $session = GroupChallengeSession::create([
            'host_id' => $host->id,
            'status' => GroupChallengeSession::STATUS_IN_PROGRESS,
            'started_at' => now(),
        ]);
        GroupChallengeParticipant::create([
            'session_id' => $session->id,
            'user_id' => $host->id,
            'invite_status' => GroupChallengeParticipant::INVITE_STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);
        GroupChallengeParticipant::create([
            'session_id' => $session->id,
            'user_id' => $teammate->id,
            'invite_status' => GroupChallengeParticipant::INVITE_STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);

        return $session;
    }

    public function test_host_can_pause_and_resume_session(): void
    {
        Event::fake();

        $host = $this->makeUser('in_challenge');
        $teammate = $this->makeUser('in_challenge');
        $this->connectTeammates($host, $teammate);
        $session = $this->createInProgressSession($host, $teammate);

        Sanctum::actingAs($host);

        $this->postJson("/api/v1/group-challenges/{$session->id}/pause")->assertStatus(200);
        $this->assertSame(GroupChallengeSession::STATUS_PAUSED, $session->fresh()->status);
        Event::assertDispatched(GroupChallengePaused::class, fn($event) => $event->sessionId === $session->id);

        $this->postJson("/api/v1/group-challenges/{$session->id}/resume")->assertStatus(200);
        $this->assertSame(GroupChallengeSession::STATUS_IN_PROGRESS, $session->fresh()->status);
        Event::assertDispatched(GroupChallengeResumed::class, fn($event) => $event->sessionId === $session->id);
    }

    public function test_non_host_cannot_pause_session(): void
    {
        $host = $this->makeUser('in_challenge');
        $teammate = $this->makeUser('in_challenge');
        $this->connectTeammates($host, $teammate);
        $session = $this->createInProgressSession($host, $teammate);

        Sanctum::actingAs($teammate);

        $this->postJson("/api/v1/group-challenges/{$session->id}/pause")->assertStatus(403);
    }

    public function test_host_can_cancel_an_active_session(): void
    {
        Event::fake();

        $host = $this->makeUser('in_challenge');
        $teammate = $this->makeUser('in_challenge');
        $this->connectTeammates($host, $teammate);
        $session = $this->createInProgressSession($host, $teammate);

        Sanctum::actingAs($host);

        $this->postJson("/api/v1/group-challenges/{$session->id}/cancel")->assertStatus(200);

        $this->assertSame(GroupChallengeSession::STATUS_CANCELLED, $session->fresh()->status);
        $this->assertSame('ready', $host->fresh()->status);
        $this->assertSame('ready', $teammate->fresh()->status);
        Event::assertDispatched(GroupChallengeCancelled::class, fn($event) => $event->sessionId === $session->id);
    }

    public function test_host_cannot_leave_session(): void
    {
        $host = $this->makeUser('in_challenge');
        $teammate = $this->makeUser('in_challenge');
        $this->connectTeammates($host, $teammate);
        $session = $this->createInProgressSession($host, $teammate);

        Sanctum::actingAs($host);

        $this->postJson("/api/v1/group-challenges/{$session->id}/leave")->assertStatus(403);
    }

    public function test_last_teammate_leaving_auto_cancels_session(): void
    {
        Event::fake();

        $host = $this->makeUser('in_challenge');
        $teammate = $this->makeUser('in_challenge');
        $this->connectTeammates($host, $teammate);
        $session = $this->createInProgressSession($host, $teammate);

        Sanctum::actingAs($teammate);

        $this->postJson("/api/v1/group-challenges/{$session->id}/leave")->assertStatus(200);

        $this->assertSame('ready', $teammate->fresh()->status);
        Event::assertDispatched(GroupChallengeParticipantLeft::class, fn($event) => $event->userId === $teammate->id);

        $this->assertSame(GroupChallengeSession::STATUS_CANCELLED, $session->fresh()->status);
        $this->assertSame('ready', $host->fresh()->status);
        Event::assertDispatched(GroupChallengeCancelled::class);
    }

    public function test_host_can_remove_a_participant_and_their_status_becomes_ready(): void
    {
        Event::fake();

        $host = $this->makeUser('in_challenge');
        $teammate = $this->makeUser('in_challenge');
        $other = $this->makeUser('in_challenge');
        $this->connectTeammates($host, $teammate);
        $session = $this->createInProgressSession($host, $teammate);
        GroupChallengeParticipant::create([
            'session_id' => $session->id,
            'user_id' => $other->id,
            'invite_status' => GroupChallengeParticipant::INVITE_STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);

        Sanctum::actingAs($host);

        $this->deleteJson("/api/v1/group-challenges/{$session->id}/participants/{$teammate->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('group_challenge_participants', [
            'session_id' => $session->id,
            'user_id' => $teammate->id,
        ]);
        $this->assertSame('ready', $teammate->fresh()->status);
        Event::assertDispatched(GroupChallengeParticipantLeft::class, fn($event) => $event->userId === $teammate->id);
    }

    public function test_non_host_cannot_remove_a_participant(): void
    {
        $host = $this->makeUser('in_challenge');
        $teammate = $this->makeUser('in_challenge');
        $this->connectTeammates($host, $teammate);
        $session = $this->createInProgressSession($host, $teammate);

        Sanctum::actingAs($teammate);

        $this->deleteJson("/api/v1/group-challenges/{$session->id}/participants/{$host->id}")
            ->assertStatus(403);
    }

    public function test_removing_last_teammate_auto_cancels_session(): void
    {
        Event::fake();

        $host = $this->makeUser('in_challenge');
        $teammate = $this->makeUser('in_challenge');
        $this->connectTeammates($host, $teammate);
        $session = $this->createInProgressSession($host, $teammate);

        Sanctum::actingAs($host);

        $this->deleteJson("/api/v1/group-challenges/{$session->id}/participants/{$teammate->id}")
            ->assertStatus(200);

        $this->assertSame('ready', $teammate->fresh()->status);
        $this->assertSame(GroupChallengeSession::STATUS_CANCELLED, $session->fresh()->status);
        $this->assertSame('ready', $host->fresh()->status);
        Event::assertDispatched(GroupChallengeCancelled::class);
    }

    public function test_user_can_register_fcm_token(): void
    {
        $user = $this->makeUser('ready');
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/user/fcm-token', ['fcm_token' => 'test-device-token'])
            ->assertStatus(200);

        $this->assertSame('test-device-token', $user->fresh()->fcm_token);
    }

    public function test_start_rejects_busy_teammate(): void
    {
        Event::fake();

        $host = $this->makeUser('ready');
        $teammate = $this->makeUser('in_challenge');
        $this->connectTeammates($host, $teammate);

        Sanctum::actingAs($host);

        $response = $this->postJson('/api/v1/group-challenges/start', [
            'teammate_ids' => [$teammate->id],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('group_challenge_sessions', 0);
        Event::assertNotDispatched(ChallengeInviteReceived::class);
    }

    public function test_start_creates_pending_session_and_invites_teammate(): void
    {
        Event::fake();

        $host = $this->makeUser('ready');
        $teammate = $this->makeUser('ready');
        $this->connectTeammates($host, $teammate);

        Sanctum::actingAs($host);

        $response = $this->postJson('/api/v1/group-challenges/start', [
            'teammate_ids' => [$teammate->id],
        ]);

        $response->assertStatus(201);
        $sessionId = $response->json('session.id');

        $this->assertDatabaseHas('group_challenge_sessions', [
            'id' => $sessionId,
            'host_id' => $host->id,
            'status' => GroupChallengeSession::STATUS_PENDING,
        ]);

        $this->assertDatabaseHas('group_challenge_participants', [
            'session_id' => $sessionId,
            'user_id' => $teammate->id,
            'invite_status' => GroupChallengeParticipant::INVITE_STATUS_INVITED,
        ]);

        $this->assertSame('in_challenge', $host->fresh()->status);
        Event::assertDispatched(ChallengeInviteReceived::class, fn($event) => $event->recipientId === $teammate->id);
    }

    public function test_anyone_can_view_all_users_challenge_totals(): void
    {
        $host = $this->makeUser('ready');
        $teammate = $this->makeUser('ready');
        $startedAt = now()->subMinutes(25);

        $session = GroupChallengeSession::create([
            'host_id' => $host->id,
            'status' => GroupChallengeSession::STATUS_COMPLETED,
            'started_at' => $startedAt,
            'ended_at' => now(),
        ]);

        GroupChallengeParticipant::create([
            'session_id' => $session->id,
            'user_id' => $teammate->id,
            'invite_status' => GroupChallengeParticipant::INVITE_STATUS_ACCEPTED,
            'responded_at' => $startedAt,
        ]);

        $this->getJson('/api/v1/leaderboard')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment([
                'host_id' => $host->id,
                'host_name' => $host->name,
                'user_id' => $host->id,
                'user_name' => $host->name,
                'total_challenge_count' => 1,
                'total_duration_minutes' => 25,
            ])
            ->assertJsonFragment([
                'host_id' => $teammate->id,
                'host_name' => $teammate->name,
                'user_id' => $teammate->id,
                'user_name' => $teammate->name,
                'total_challenge_count' => 1,
                'total_duration_minutes' => 25,
            ]);
    }

    public function test_user_can_view_emotion_counts(): void
    {
        $user = $this->makeUser('ready');
        EmotionLogs::create([
            'user_id' => $user->id,
            'emotion' => 'Happy',
            'logged_at' => now(),
        ]);
        EmotionLogs::create([
            'user_id' => $user->id,
            'emotion' => 'Happy',
            'logged_at' => now(),
        ]);
        EmotionLogs::create([
            'user_id' => $user->id,
            'emotion' => 'Sad',
            'logged_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/emotion-log/counts')
            ->assertOk()
            ->assertJsonPath('data.Happy', 2)
            ->assertJsonPath('data.Sad', 1);
    }

    public function test_host_can_invite_a_user_who_is_not_on_their_team(): void
    {
        Event::fake();

        $host = $this->makeUser('ready');
        $lobbyMember = $this->makeUser('ready');

        Sanctum::actingAs($host);

        $response = $this->postJson('/api/v1/group-challenges/start', [
            'teammate_ids' => [$lobbyMember->id],
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('group_challenge_participants', [
            'session_id' => $response->json('session.id'),
            'user_id' => $lobbyMember->id,
            'invite_status' => GroupChallengeParticipant::INVITE_STATUS_INVITED,
        ]);
    }

    public function test_host_can_invite_more_teammates_to_pending_session(): void
    {
        Event::fake();

        $host = $this->makeUser('ready');
        $existing = $this->makeUser('ready');
        $newInvitee = $this->makeUser('ready');
        $this->connectTeammates($host, $existing);
        $this->connectTeammates($host, $newInvitee);

        Sanctum::actingAs($host);

        $session = GroupChallengeSession::create([
            'host_id' => $host->id,
            'status' => GroupChallengeSession::STATUS_PENDING,
        ]);
        GroupChallengeParticipant::create([
            'session_id' => $session->id,
            'user_id' => $host->id,
            'invite_status' => GroupChallengeParticipant::INVITE_STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);
        GroupChallengeParticipant::create([
            'session_id' => $session->id,
            'user_id' => $existing->id,
            'invite_status' => GroupChallengeParticipant::INVITE_STATUS_INVITED,
        ]);

        $response = $this->postJson("/api/v1/group-challenges/{$session->id}/invite", [
            'invited_user_ids' => [$newInvitee->id, $existing->id],
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('group_challenge_participants', [
            'session_id' => $session->id,
            'user_id' => $newInvitee->id,
            'invite_status' => GroupChallengeParticipant::INVITE_STATUS_INVITED,
        ]);
        $this->assertSame(3, GroupChallengeParticipant::where('session_id', $session->id)->count());
        Event::assertDispatched(ChallengeInviteReceived::class, fn($event) => $event->recipientId === $newInvitee->id);
        // Resend: $existing was already 'invited' and was included in the request too.
        Event::assertDispatched(ChallengeInviteReceived::class, fn($event) => $event->recipientId === $existing->id);
        Event::assertDispatched(GroupChallengeLobbyUpdated::class);
    }

    public function test_invite_resends_to_a_waiting_participant_without_duplicating_the_row(): void
    {
        Event::fake();

        $host = $this->makeUser('ready');
        $waiting = $this->makeUser('ready');

        $session = GroupChallengeSession::create(['host_id' => $host->id, 'status' => GroupChallengeSession::STATUS_PENDING]);
        GroupChallengeParticipant::create([
            'session_id' => $session->id,
            'user_id' => $host->id,
            'invite_status' => GroupChallengeParticipant::INVITE_STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);
        GroupChallengeParticipant::create([
            'session_id' => $session->id,
            'user_id' => $waiting->id,
            'invite_status' => GroupChallengeParticipant::INVITE_STATUS_INVITED,
        ]);

        Sanctum::actingAs($host);

        // Client sends the resend under `teammate_ids` (no `invited_user_ids`).
        $response = $this->postJson("/api/v1/group-challenges/{$session->id}/invite", [
            'teammate_ids' => [$waiting->id],
        ]);

        $response->assertStatus(201);
        $this->assertSame(2, GroupChallengeParticipant::where('session_id', $session->id)->count());
        Event::assertDispatched(ChallengeInviteReceived::class, fn($event) => $event->recipientId === $waiting->id);
    }

    public function test_invite_reactivates_a_declined_participant(): void
    {
        Event::fake();

        $host = $this->makeUser('ready');
        $declined = $this->makeUser('ready');

        $session = GroupChallengeSession::create(['host_id' => $host->id, 'status' => GroupChallengeSession::STATUS_PENDING]);
        GroupChallengeParticipant::create([
            'session_id' => $session->id,
            'user_id' => $host->id,
            'invite_status' => GroupChallengeParticipant::INVITE_STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);
        GroupChallengeParticipant::create([
            'session_id' => $session->id,
            'user_id' => $declined->id,
            'invite_status' => GroupChallengeParticipant::INVITE_STATUS_DECLINED,
            'responded_at' => now(),
        ]);

        Sanctum::actingAs($host);

        $this->postJson("/api/v1/group-challenges/{$session->id}/invite", [
            'invited_user_ids' => [$declined->id],
        ])->assertStatus(201);

        $this->assertDatabaseHas('group_challenge_participants', [
            'session_id' => $session->id,
            'user_id' => $declined->id,
            'invite_status' => GroupChallengeParticipant::INVITE_STATUS_INVITED,
        ]);
        Event::assertDispatched(ChallengeInviteReceived::class, fn($event) => $event->recipientId === $declined->id);
    }

    public function test_invite_skips_an_already_accepted_participant(): void
    {
        Event::fake();

        $host = $this->makeUser('ready');
        $accepted = $this->makeUser('ready');

        $session = GroupChallengeSession::create(['host_id' => $host->id, 'status' => GroupChallengeSession::STATUS_PENDING]);
        GroupChallengeParticipant::create([
            'session_id' => $session->id,
            'user_id' => $host->id,
            'invite_status' => GroupChallengeParticipant::INVITE_STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);
        GroupChallengeParticipant::create([
            'session_id' => $session->id,
            'user_id' => $accepted->id,
            'invite_status' => GroupChallengeParticipant::INVITE_STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);

        Sanctum::actingAs($host);

        $this->postJson("/api/v1/group-challenges/{$session->id}/invite", [
            'invited_user_ids' => [$accepted->id],
        ])->assertStatus(422);

        Event::assertNotDispatched(ChallengeInviteReceived::class);
    }

    public function test_non_host_cannot_invite_more_teammates(): void
    {
        $host = $this->makeUser('ready');
        $teammate = $this->makeUser('ready');
        $newInvitee = $this->makeUser('ready');
        $this->connectTeammates($host, $teammate);

        $session = GroupChallengeSession::create(['host_id' => $host->id, 'status' => GroupChallengeSession::STATUS_PENDING]);
        GroupChallengeParticipant::create([
            'session_id' => $session->id,
            'user_id' => $host->id,
            'invite_status' => GroupChallengeParticipant::INVITE_STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);
        GroupChallengeParticipant::create([
            'session_id' => $session->id,
            'user_id' => $teammate->id,
            'invite_status' => GroupChallengeParticipant::INVITE_STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);

        Sanctum::actingAs($teammate);

        $this->postJson("/api/v1/group-challenges/{$session->id}/invite", [
            'invited_user_ids' => [$newInvitee->id],
        ])->assertStatus(403);
    }

    public function test_participant_can_view_session_with_participants_eager_loaded(): void
    {
        $host = $this->makeUser('ready');
        $teammate = $this->makeUser('ready');
        $this->connectTeammates($host, $teammate);
        $session = $this->createInProgressSession($host, $teammate);

        Sanctum::actingAs($teammate);

        $response = $this->getJson("/api/v1/group-challenges/{$session->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('session.id', $session->id);
        $response->assertJsonCount(2, 'session.participants');
        $response->assertJsonPath('session.participants.0.user.name', $host->name);
        $response->assertJsonMissingPath('session.participants.0.user.fcm_token');
    }

    public function test_non_participant_cannot_view_session(): void
    {
        $host = $this->makeUser('ready');
        $teammate = $this->makeUser('ready');
        $outsider = $this->makeUser('ready');
        $this->connectTeammates($host, $teammate);
        $session = $this->createInProgressSession($host, $teammate);

        Sanctum::actingAs($outsider);

        $this->getJson("/api/v1/group-challenges/{$session->id}")->assertStatus(404);
    }

    public function test_invitee_can_accept_and_joins_lobby(): void
    {
        Event::fake();

        $host = $this->makeUser('ready');
        $teammate = $this->makeUser('ready');
        $this->connectTeammates($host, $teammate);

        $session = GroupChallengeSession::create([
            'host_id' => $host->id,
            'status' => GroupChallengeSession::STATUS_PENDING,
        ]);
        GroupChallengeParticipant::create([
            'session_id' => $session->id,
            'user_id' => $host->id,
            'invite_status' => GroupChallengeParticipant::INVITE_STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);
        GroupChallengeParticipant::create([
            'session_id' => $session->id,
            'user_id' => $teammate->id,
            'invite_status' => GroupChallengeParticipant::INVITE_STATUS_INVITED,
        ]);

        Sanctum::actingAs($teammate);

        $response = $this->postJson("/api/v1/group-challenges/{$session->id}/accept");

        $response->assertStatus(200);
        $this->assertDatabaseHas('group_challenge_participants', [
            'session_id' => $session->id,
            'user_id' => $teammate->id,
            'invite_status' => GroupChallengeParticipant::INVITE_STATUS_ACCEPTED,
        ]);
        $this->assertSame('in_challenge', $teammate->fresh()->status);
        Event::assertDispatched(GroupChallengeLobbyUpdated::class);
    }

    public function test_non_host_cannot_begin_session(): void
    {
        $host = $this->makeUser('in_challenge');
        $teammate = $this->makeUser('in_challenge');
        $this->connectTeammates($host, $teammate);

        $session = GroupChallengeSession::create([
            'host_id' => $host->id,
            'status' => GroupChallengeSession::STATUS_PENDING,
        ]);
        GroupChallengeParticipant::create([
            'session_id' => $session->id,
            'user_id' => $host->id,
            'invite_status' => GroupChallengeParticipant::INVITE_STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);
        GroupChallengeParticipant::create([
            'session_id' => $session->id,
            'user_id' => $teammate->id,
            'invite_status' => GroupChallengeParticipant::INVITE_STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);

        Sanctum::actingAs($teammate);

        $this->postJson("/api/v1/group-challenges/{$session->id}/begin")
            ->assertStatus(403);
    }

    public function test_begin_fails_while_invite_still_pending(): void
    {
        $host = $this->makeUser('in_challenge');
        $teammate = $this->makeUser('ready');
        $this->connectTeammates($host, $teammate);

        $session = GroupChallengeSession::create([
            'host_id' => $host->id,
            'status' => GroupChallengeSession::STATUS_PENDING,
        ]);
        GroupChallengeParticipant::create([
            'session_id' => $session->id,
            'user_id' => $host->id,
            'invite_status' => GroupChallengeParticipant::INVITE_STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);
        GroupChallengeParticipant::create([
            'session_id' => $session->id,
            'user_id' => $teammate->id,
            'invite_status' => GroupChallengeParticipant::INVITE_STATUS_INVITED,
        ]);

        Sanctum::actingAs($host);

        $this->postJson("/api/v1/group-challenges/{$session->id}/begin")
            ->assertStatus(422);

        $this->assertSame(GroupChallengeSession::STATUS_PENDING, $session->fresh()->status);
    }

    public function test_host_can_begin_once_teammate_accepted(): void
    {
        Event::fake();

        $host = $this->makeUser('in_challenge');
        $teammate = $this->makeUser('in_challenge');
        $this->connectTeammates($host, $teammate);

        $session = GroupChallengeSession::create([
            'host_id' => $host->id,
            'status' => GroupChallengeSession::STATUS_PENDING,
        ]);
        GroupChallengeParticipant::create([
            'session_id' => $session->id,
            'user_id' => $host->id,
            'invite_status' => GroupChallengeParticipant::INVITE_STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);
        GroupChallengeParticipant::create([
            'session_id' => $session->id,
            'user_id' => $teammate->id,
            'invite_status' => GroupChallengeParticipant::INVITE_STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);

        Sanctum::actingAs($host);

        $response = $this->postJson("/api/v1/group-challenges/{$session->id}/begin");

        $response->assertStatus(200);
        $session->refresh();
        $this->assertSame(GroupChallengeSession::STATUS_IN_PROGRESS, $session->status);
        $this->assertNotNull($session->started_at);
        Event::assertDispatched(GroupChallengeStarted::class, fn($event) => $event->sessionId === $session->id);
    }

    public function test_progress_and_complete_finish_the_session_for_all_participants(): void
    {
        Event::fake();

        $host = $this->makeUser('in_challenge');
        $teammate = $this->makeUser('in_challenge');
        $this->connectTeammates($host, $teammate);

        $session = GroupChallengeSession::create([
            'host_id' => $host->id,
            'status' => GroupChallengeSession::STATUS_IN_PROGRESS,
            'started_at' => now(),
        ]);
        GroupChallengeParticipant::create([
            'session_id' => $session->id,
            'user_id' => $host->id,
            'invite_status' => GroupChallengeParticipant::INVITE_STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);
        GroupChallengeParticipant::create([
            'session_id' => $session->id,
            'user_id' => $teammate->id,
            'invite_status' => GroupChallengeParticipant::INVITE_STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);

        Sanctum::actingAs($host);
        $this->postJson("/api/v1/group-challenges/{$session->id}/progress", ['progress' => 50])
            ->assertStatus(200);
        $this->postJson("/api/v1/group-challenges/{$session->id}/complete")
            ->assertStatus(200);

        $this->assertSame('ready', $host->fresh()->status);
        $this->assertSame(GroupChallengeSession::STATUS_IN_PROGRESS, $session->fresh()->status);

        Sanctum::actingAs($teammate);
        $this->postJson("/api/v1/group-challenges/{$session->id}/complete")
            ->assertStatus(200);

        $this->assertSame(GroupChallengeSession::STATUS_COMPLETED, $session->fresh()->status);
        $this->assertSame('ready', $teammate->fresh()->status);
        Event::assertDispatched(GroupChallengeCompleted::class, fn($event) => $event->payload['session_completed'] === true);
    }
}
