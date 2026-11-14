<?php

namespace App\Services;

use App\Events\ChallengeInviteReceived;
use App\Events\GroupChallengeCancelled;
use App\Events\GroupChallengeCompleted;
use App\Events\GroupChallengeLobbyUpdated;
use App\Events\GroupChallengeParticipantLeft;
use App\Events\GroupChallengePaused;
use App\Events\GroupChallengeProgressUpdated;
use App\Events\GroupChallengeResumed;
use App\Events\GroupChallengeStarted;
use App\Exceptions\GroupChallengeException;
use App\Models\GroupChallengeParticipant;
use App\Models\GroupChallengeSession;
use App\Models\Activity;
use App\Models\User;
use App\Models\Users;
use App\Support\GroupChallengeStatus;
use App\Services\Membership\MembershipAccessService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GroupChallengeService
{
    public function __construct(
        protected AuthService $authService,
        protected FcmService $fcmService,
        protected MembershipAccessService $membershipAccess,
    ) {}

    /**
     * @param array<int, int|string> $teammateIds
     */
    public function start(User|Users $host, array $teammateIds, ?int $challengeId): GroupChallengeSession
    {
        $teammateIds = collect($teammateIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->reject(fn ($id) => $id === $host->id)
            ->values();

        return DB::transaction(function () use ($host, $teammateIds, $challengeId) {
            $participantIds = $teammateIds->concat([$host->id])->unique()->values();

            $users = User::whereIn('id', $participantIds)->lockForUpdate()->get()->keyBy('id');

            $this->assertAllReady($users);
            $activity = $challengeId ? Activity::find($challengeId) : null;
            if ($challengeId && ! $activity) throw new GroupChallengeException('Challenge not found.', 404);
            foreach ($users as $participant) $this->assertMembershipAllowsActivity($participant, $activity);

            $session = GroupChallengeSession::create([
                'challenge_id' => $challengeId,
                'host_id' => $host->id,
                'status' => GroupChallengeSession::STATUS_PENDING,
            ]);

            GroupChallengeParticipant::create([
                'session_id' => $session->id,
                'user_id' => $host->id,
                'invite_status' => GroupChallengeParticipant::INVITE_STATUS_ACCEPTED,
                'responded_at' => now(),
            ]);

            foreach ($teammateIds as $teammateId) {
                GroupChallengeParticipant::create([
                    'session_id' => $session->id,
                    'user_id' => $teammateId,
                    'invite_status' => GroupChallengeParticipant::INVITE_STATUS_INVITED,
                ]);
            }

            $this->authService->updateStatusForUser($host->id, GroupChallengeStatus::GROUP_CHALLENGE_STATUS_IN_CHALLENGE);

            $this->notifyInvitees($session, $host, $users->only($teammateIds->all()));

            return $session->load('participants');
        });
    }

    /**
     * @param array<int, int|string> $invitedUserIds
     */
    public function inviteMore(User|Users $host, GroupChallengeSession $session, array $invitedUserIds): GroupChallengeSession
    {
        if ($session->host_id !== $host->id) {
            throw new GroupChallengeException('Only the host can invite more teammates.', 403);
        }

        $this->assertSessionPending($session);

        $requestedIds = collect($invitedUserIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->reject(fn ($id) => $id === $host->id)
            ->values();

        if ($requestedIds->isEmpty()) {
            throw new GroupChallengeException('No teammates selected to invite.');
        }

        return DB::transaction(function () use ($host, $session, $requestedIds) {
            $this->assertMembershipAllowsActivity($host, $session->challenge);
            $existingParticipants = $session->participants()
                ->whereIn('user_id', $requestedIds)
                ->get()
                ->keyBy('user_id');

            $brandNewIds = $requestedIds->reject(fn ($id) => $existingParticipants->has($id))->values();

            if ($brandNewIds->isNotEmpty()) {
                $newUsers = User::whereIn('id', $brandNewIds)->lockForUpdate()->get()->keyBy('id');

                $this->assertAllReady($newUsers);
                foreach ($newUsers as $newUser) $this->assertMembershipAllowsActivity($newUser, $session->challenge);

                foreach ($brandNewIds as $id) {
                    GroupChallengeParticipant::create([
                        'session_id' => $session->id,
                        'user_id' => $id,
                        'invite_status' => GroupChallengeParticipant::INVITE_STATUS_INVITED,
                    ]);
                }
            }

            $resendIds = collect();
            foreach ($existingParticipants as $userId => $participant) {
                if ($participant->invite_status === GroupChallengeParticipant::INVITE_STATUS_ACCEPTED) {
                    continue;
                }

                if ($participant->invite_status !== GroupChallengeParticipant::INVITE_STATUS_INVITED) {
                    $participant->update([
                        'invite_status' => GroupChallengeParticipant::INVITE_STATUS_INVITED,
                        'responded_at' => null,
                        'left_at' => null,
                    ]);
                }

                $resendIds->push($userId);
            }

            $notifyIds = $brandNewIds->concat($resendIds)->unique()->values();

            if ($notifyIds->isEmpty()) {
                throw new GroupChallengeException('Everyone selected has already joined this challenge.');
            }

            $this->notifyInvitees($session, $host, User::whereIn('id', $notifyIds)->get());

            $this->broadcastLobby($session);

            return $session->load('participants.user');
        });
    }

    public function getSessionForUser(User|Users $user, GroupChallengeSession $session): GroupChallengeSession
    {
        $this->findParticipantOrFail($session, $user);

        return $session->load(['host', 'participants.user']);
    }

    public function accept(User|Users $user, GroupChallengeSession $session): GroupChallengeParticipant
    {
        $this->assertSessionPending($session);

        $participant = $this->findParticipantOrFail($session, $user);

        $this->assertMembershipAllowsActivity($user, $session->challenge);

        if ($participant->invite_status !== GroupChallengeParticipant::INVITE_STATUS_INVITED) {
            throw new GroupChallengeException('You have already responded to this invite.');
        }

        if ($user->fresh()->status !== GroupChallengeStatus::GROUP_CHALLENGE_STATUS_READY) {
            $participant->update([
                'invite_status' => GroupChallengeParticipant::INVITE_STATUS_DECLINED,
                'responded_at' => now(),
            ]);

            $this->broadcastLobby($session);

            throw new GroupChallengeException('You are currently busy and cannot join this challenge.');
        }

        $participant->update([
            'invite_status' => GroupChallengeParticipant::INVITE_STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);

        $this->authService->updateStatusForUser($user->id, GroupChallengeStatus::GROUP_CHALLENGE_STATUS_IN_CHALLENGE);

        $this->broadcastLobby($session);

        return $participant;
    }

    public function decline(User|Users $user, GroupChallengeSession $session): GroupChallengeParticipant
    {
        $this->assertSessionPending($session);

        $participant = $this->findParticipantOrFail($session, $user);

        if ($participant->invite_status === GroupChallengeParticipant::INVITE_STATUS_DECLINED) {
            return $participant;
        }

        $wasAccepted = $participant->invite_status === GroupChallengeParticipant::INVITE_STATUS_ACCEPTED;

        $participant->update([
            'invite_status' => GroupChallengeParticipant::INVITE_STATUS_DECLINED,
            'responded_at' => now(),
        ]);

        if ($wasAccepted) {
            $this->authService->updateStatusForUser($user->id, GroupChallengeStatus::GROUP_CHALLENGE_STATUS_READY);
        }

        $remaining = $session->participants()
            ->where('user_id', '!=', $session->host_id)
            ->where('invite_status', '!=', GroupChallengeParticipant::INVITE_STATUS_DECLINED)
            ->count();

        if ($remaining === 0) {
            $this->cancel($session, 'Everyone declined the invite.');

            return $participant;
        }

        $this->broadcastLobby($session);

        return $participant;
    }

    public function begin(User|Users $host, GroupChallengeSession $session): GroupChallengeSession
    {
        if ($session->host_id !== $host->id) {
            throw new GroupChallengeException('Only the host can start this challenge.', 403);
        }

        $this->assertSessionPending($session);

        $invitees = $session->participants()->where('user_id', '!=', $host->id)->get();

        if ($invitees->contains(fn(GroupChallengeParticipant $p) => $p->invite_status === GroupChallengeParticipant::INVITE_STATUS_INVITED)) {
            throw new GroupChallengeException('Waiting for everyone to respond to the invite.');
        }

        if ($invitees->where('invite_status', GroupChallengeParticipant::INVITE_STATUS_ACCEPTED)->isEmpty()) {
            throw new GroupChallengeException('No teammates have joined this challenge yet.');
        }

        foreach ($session->participants()->where('invite_status', GroupChallengeParticipant::INVITE_STATUS_ACCEPTED)->with('user')->get() as $participant) {
            if ($participant->user) $this->assertMembershipAllowsActivity($participant->user, $session->challenge);
        }

        $session->update([
            'status' => GroupChallengeSession::STATUS_IN_PROGRESS,
            'started_at' => now(),
        ]);

        $activeParticipants = $session->participants()
            ->where('invite_status', GroupChallengeParticipant::INVITE_STATUS_ACCEPTED)
            ->with('user')
            ->get();

        event(new GroupChallengeStarted(
            sessionId: $session->id,
            payload: [
                'session_id' => $session->id,
                'challenge_id' => $session->challenge_id,
                'host_id' => $session->host_id,
                'started_at' => $session->started_at->toIso8601String(),
                'participant_ids' => $activeParticipants->pluck('user_id'),
            ],
        ));

        $this->fcmService->sendToMany(
            $activeParticipants->pluck('user')->filter(),
            'Challenge Started! 🏁',
            'Your group challenge has begun — jump back in!',
            [
                'type' => 'GROUP_CHALLENGE_STARTED',
                'session_id' => $session->id,
                'challenge_id' => $session->challenge_id,
            ],
        );

        return $session;
    }

    public function cancelByHost(User|Users $host, GroupChallengeSession $session): GroupChallengeSession
    {
        if ($session->host_id !== $host->id) {
            throw new GroupChallengeException('Only the host can cancel this challenge.', 403);
        }

        $this->assertSessionActive($session);

        return $this->cancel($session, 'Host cancelled the session.');
    }

    public function pause(User|Users $host, GroupChallengeSession $session): GroupChallengeSession
    {
        if ($session->host_id !== $host->id) {
            throw new GroupChallengeException('Only the host can pause this challenge.', 403);
        }

        if ($session->status !== GroupChallengeSession::STATUS_IN_PROGRESS) {
            throw new GroupChallengeException('This challenge is not currently in progress.');
        }

        $session->update(['status' => GroupChallengeSession::STATUS_PAUSED]);

        event(new GroupChallengePaused(sessionId: $session->id));

        return $session;
    }

    public function resume(User|Users $host, GroupChallengeSession $session): GroupChallengeSession
    {
        if ($session->host_id !== $host->id) {
            throw new GroupChallengeException('Only the host can resume this challenge.', 403);
        }

        if ($session->status !== GroupChallengeSession::STATUS_PAUSED) {
            throw new GroupChallengeException('This challenge is not currently paused.');
        }

        foreach ($session->participants()->where('invite_status', GroupChallengeParticipant::INVITE_STATUS_ACCEPTED)->with('user')->get() as $participant) {
            if ($participant->user) $this->assertMembershipAllowsActivity($participant->user, $session->challenge);
        }

        $session->update(['status' => GroupChallengeSession::STATUS_IN_PROGRESS]);

        event(new GroupChallengeResumed(sessionId: $session->id));

        return $session;
    }

    public function leave(User|Users $user, GroupChallengeSession $session): GroupChallengeParticipant
    {
        if ($session->host_id === $user->id) {
            throw new GroupChallengeException('The host cannot leave — cancel the challenge instead.', 403);
        }

        if (! in_array($session->status, [GroupChallengeSession::STATUS_IN_PROGRESS, GroupChallengeSession::STATUS_PAUSED], true)) {
            throw new GroupChallengeException('This challenge is not currently active.');
        }

        $participant = $this->findAcceptedParticipantOrFail($session, $user);

        $participant->update([
            'invite_status' => GroupChallengeParticipant::INVITE_STATUS_LEFT,
            'left_at' => now(),
        ]);

        $this->authService->updateStatusForUser($user->id, GroupChallengeStatus::GROUP_CHALLENGE_STATUS_READY);

        event(new GroupChallengeParticipantLeft(
            sessionId: $session->id,
            userId: $user->id,
            userName: $user->name,
        ));

        $remainingTeammates = $session->participants()
            ->where('user_id', '!=', $session->host_id)
            ->where('invite_status', GroupChallengeParticipant::INVITE_STATUS_ACCEPTED)
            ->count();

        if ($remainingTeammates === 0) {
            $this->cancel($session, 'All participants left.');
        }

        return $participant;
    }

    public function removeParticipant(User|Users $host, GroupChallengeSession $session, int $userId): GroupChallengeParticipant
    {
        if ($session->host_id !== $host->id) {
            throw new GroupChallengeException('Only the host can remove participants.', 403);
        }

        if ($userId === $session->host_id) {
            throw new GroupChallengeException('The host cannot be removed — cancel the challenge instead.', 403);
        }

        $this->assertSessionActive($session);

        $removedUser = User::find($userId);

        if (! $removedUser) {
            throw new GroupChallengeException('User not found.', 404);
        }

        $participant = $session->participants()->where('user_id', $userId)->first();

        if (! $participant) {
            throw new GroupChallengeException('That user is not part of this challenge.', 404);
        }

        $wasActive = in_array($participant->invite_status, [
            GroupChallengeParticipant::INVITE_STATUS_INVITED,
            GroupChallengeParticipant::INVITE_STATUS_ACCEPTED,
        ], true);

        // Hard-delete the row for this user from group_challenge_participants.
        $removedParticipant = $participant->replicate();
        $removedParticipant->id = $participant->id;
        $participant->delete();

        $this->authService->updateStatusForUser($userId, GroupChallengeStatus::GROUP_CHALLENGE_STATUS_READY);

        event(new GroupChallengeParticipantLeft(
            sessionId: $session->id,
            userId: $userId,
            userName: $removedUser->name ?? 'Teammate',
        ));

        $remainingTeammates = $session->participants()
            ->where('user_id', '!=', $session->host_id)
            ->where('invite_status', GroupChallengeParticipant::INVITE_STATUS_ACCEPTED)
            ->count();

        if ($wasActive && $remainingTeammates === 0 && $session->status !== GroupChallengeSession::STATUS_PENDING) {
            $this->cancel($session, 'All participants were removed.');
        } else {
            $this->broadcastLobby($session);
        }

        return $removedParticipant;
    }

    public function updateProgress(User|Users $user, GroupChallengeSession $session, int $progress): GroupChallengeParticipant
    {
        if ($session->status !== GroupChallengeSession::STATUS_IN_PROGRESS) {
            throw new GroupChallengeException('This challenge is not in progress.');
        }

        $participant = $this->findAcceptedParticipantOrFail($session, $user);
        $this->assertMembershipAllowsActivity($user, $session->challenge);

        $participant->update(['progress' => $progress]);

        event(new GroupChallengeProgressUpdated(
            sessionId: $session->id,
            payload: [
                'session_id' => $session->id,
                'user_id' => $user->id,
                'progress' => $progress,
            ],
        ));

        return $participant;
    }

    public function complete(User|Users $user, GroupChallengeSession $session): GroupChallengeParticipant
    {
        $participant = $this->findAcceptedParticipantOrFail($session, $user);
        if ($participant->completed_at) return $participant;

        if ($session->status !== GroupChallengeSession::STATUS_IN_PROGRESS) {
            throw new GroupChallengeException('This challenge is not in progress.');
        }

        $this->assertMembershipAllowsActivity($user, $session->challenge);

        $participant->update(['completed_at' => now()]);

        $this->authService->updateStatusForUser($user->id, GroupChallengeStatus::GROUP_CHALLENGE_STATUS_READY);

        $activeParticipants = $session->participants()
            ->where('invite_status', GroupChallengeParticipant::INVITE_STATUS_ACCEPTED)
            ->get();

        $sessionCompleted = $activeParticipants->every(fn(GroupChallengeParticipant $p) => $p->completed_at !== null);

        if ($sessionCompleted) {
            $session->update([
                'status' => GroupChallengeSession::STATUS_COMPLETED,
                'ended_at' => now(),
            ]);
        }

        event(new GroupChallengeCompleted(
            sessionId: $session->id,
            payload: [
                'session_id' => $session->id,
                'user_id' => $user->id,
                'completed_at' => $participant->completed_at->toIso8601String(),
                'session_completed' => $sessionCompleted,
                'participants' => $activeParticipants->map(fn(GroupChallengeParticipant $p) => [
                    'user_id' => $p->user_id,
                    'progress' => $p->progress,
                    'completed_at' => $p->completed_at?->toIso8601String(),
                ]),
            ],
        ));

        return $participant;
    }

    protected function cancel(GroupChallengeSession $session, string $reason): GroupChallengeSession
    {
        $activeParticipantIds = $session->participants()
            ->where('invite_status', GroupChallengeParticipant::INVITE_STATUS_ACCEPTED)
            ->pluck('user_id');

        $session->update([
            'status' => GroupChallengeSession::STATUS_CANCELLED,
            'ended_at' => now(),
        ]);

        foreach ($activeParticipantIds as $userId) {
            $this->authService->updateStatusForUser($userId, GroupChallengeStatus::GROUP_CHALLENGE_STATUS_READY);
        }

        event(new GroupChallengeCancelled(
            sessionId: $session->id,
            payload: [
                'session_id' => $session->id,
                'reason' => $reason,
            ],
        ));

        return $session;
    }

    protected function broadcastLobby(GroupChallengeSession $session): void
    {
        $participants = $session->participants()->with('user:id,name')->get();

        event(new GroupChallengeLobbyUpdated(
            sessionId: $session->id,
            payload: [
                'session_id' => $session->id,
                'status' => $session->status,
                'participants' => $participants->map(fn (GroupChallengeParticipant $p) => [
                    'user_id' => $p->user_id,
                    'name' => $p->user->name ?? 'Teammate',
                    'avatar' => $p->user->avatar ?? null,
                    'invite_status' => $p->invite_status,
                ]),
            ],
        ));
    }

    /**
     * Fires ChallengeInviteReceived + FCM push for a batch of newly invited
     * teammates. Shared by start() and inviteMore().
     *
     * @param Collection<int, User> $invitedUsers
     */
    protected function notifyInvitees(GroupChallengeSession $session, User|Users $host, Collection $invitedUsers): void
    {
        $challengeTitle = $session->challenge?->activity_title;
        $challengeId = $session->challenge_id !== null ? (string) $session->challenge_id : null;

        $invitedTeammates = $session->participants()
            ->with('user:id,name')
            ->get()
            ->pluck('user.name')
            ->filter()
            ->values();

        foreach ($invitedUsers as $invitee) {
            event(new ChallengeInviteReceived(
                recipientId: $invitee->id,
                payload: [
                    'session_id' => $session->id,
                    'challenge_id' => $challengeId,
                    'challenge_title' => $challengeTitle,
                    'host_id' => $host->id,
                    'host_name' => $host->name,
                    'invited_teammates' => $invitedTeammates,
                ],
            ));
        }

        $this->fcmService->sendToMany(
            $invitedUsers->values(),
            'Group Challenge Invite! 🤝',
            "{$host->name} invited you to " . ($challengeTitle ?? 'a group challenge'),
            [
                'type' => 'GROUP_CHALLENGE_INVITE',
                'session_id' => $session->id,
                'challenge_id' => $challengeId,
                'host_id' => $host->id,
                'host_name' => $host->name,
            ],
        );
    }

    /**
     * @param Collection<int, User> $users
     */
    protected function assertAllReady(Collection $users): void
    {
        $busyNames = $users
            ->filter(fn(User $user) => $user->status !== GroupChallengeStatus::GROUP_CHALLENGE_STATUS_READY)
            ->pluck('name');

        if ($busyNames->isEmpty()) {
            return;
        }

        $verb = $busyNames->count() > 1 ? 'are' : 'is';

        throw new GroupChallengeException($busyNames->implode(', ') . " currently {$verb} busy.");
    }

    protected function assertSessionPending(GroupChallengeSession $session): void
    {
        if ($session->status !== GroupChallengeSession::STATUS_PENDING) {
            throw new GroupChallengeException('This challenge has already started or ended.');
        }
    }

    protected function assertSessionActive(GroupChallengeSession $session): void
    {
        $activeStatuses = [
            GroupChallengeSession::STATUS_PENDING,
            GroupChallengeSession::STATUS_IN_PROGRESS,
            GroupChallengeSession::STATUS_PAUSED,
        ];

        if (! in_array($session->status, $activeStatuses, true)) {
            throw new GroupChallengeException('This challenge has already ended.');
        }
    }

    protected function findParticipantOrFail(GroupChallengeSession $session, User|Users $user): GroupChallengeParticipant
    {
        $participant = $session->participants()->where('user_id', $user->id)->first();

        if (! $participant) {
            throw new GroupChallengeException('You are not part of this challenge.', 404);
        }

        return $participant;
    }

    protected function findAcceptedParticipantOrFail(GroupChallengeSession $session, User|Users $user): GroupChallengeParticipant
    {
        $participant = $this->findParticipantOrFail($session, $user);

        if ($participant->invite_status !== GroupChallengeParticipant::INVITE_STATUS_ACCEPTED) {
            throw new GroupChallengeException('You are not part of this challenge.', 403);
        }

        return $participant;
    }

    protected function assertMembershipAllowsActivity(User|Users $user, ?Activity $activity): void
    {
        if (! $activity) return;
        $account = $user instanceof User ? $user : User::find($user->id);
        if (! $account) throw new GroupChallengeException('Account not found.', 404);

        $snapshot = $this->membershipAccess->snapshot($account);
        $maxTier = $snapshot['effective_package'] === 'premium' ? 3 : ($snapshot['effective_package'] === 'standard' ? 2 : 1);
        $allowedCategories = $snapshot['effective_package'] !== null && $snapshot['limits']['category_count'] !== null
            ? (count($snapshot['selected_category_ids']) === $snapshot['limits']['category_count'] ? $snapshot['selected_category_ids'] : [])
            : null;

        if ((int) $activity->tier > $maxTier || ($allowedCategories !== null && ! in_array((string) $activity->hobby_id, $allowedCategories, true))) {
            throw new GroupChallengeException('Your current membership does not allow this challenge.', 403);
        }
    }
}
