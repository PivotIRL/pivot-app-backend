<?php

namespace App\Http\Controllers;

use App\DTO\TeamConnectionDTO;
use App\Events\NewTeamConnectionAdded;
use App\Events\TeamConnectionRemoved;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\TeamConnection;
use App\Models\User;
use App\Models\Users;
use App\Services\InviteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Services\Membership\MembershipAccessService;

class TeamInviteController extends Controller
{
    public function __construct(
        protected InviteService $inviteService
    ) {}

    public function generateLink(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($this->atConnectionLimit($user)) return response()->json(['success' => false, 'message' => 'Your membership connection limit has been reached.'], 409);
        $result = $this->inviteService->createInvite($user);
        $invitation = $result['invite'];

        $inviteUrl = "https://api.pivotirl.com.au/invite";
        $shareMessage = "Hey! I'm using Pivot app and thought you might like it. Join me here: {$inviteUrl}";

        return response()->json([
            'success' => true,
            'invite_id' => $invitation->id,
            'invite_url' => $inviteUrl,
            'expires_at' => $invitation->expires_at,
            'share_message' => $shareMessage,
        ]);
    }

    public function generate(Request $request): JsonResponse
    {
        $users = $request->user();
        if ($this->atConnectionLimit($users)) return response()->json(['success' => false, 'message' => 'Your membership connection limit has been reached.'], 409);
        $result = $this->inviteService->createInvite($users);
        $invitation = $result['invite'];
        $token = $result['token'];

        $inviteUrl = "https://api.pivotirl.com.au/invite?token={$token}";
        $shareMessage = "Join my team on Pivot!\nTap the link: {$inviteUrl}\nOr open the app and enter code: {$invitation->code}";

        return response()->json([
            'success' => true,
            'invite_id' => $invitation->id,
            'invite_url' => $inviteUrl,
            'code' => $invitation->code,
            'expires_at' => $invitation->expires_at,
            'share_message' => $shareMessage,
        ]);
    }

    public function resolveByToken(string $token): JsonResponse
    {
        $invitation = $this->inviteService->resolvePendingInviteByToken($token);
        if (! $invitation) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired invitation'
            ], 404);
        }

        return $this->buildPreview($invitation);
    }

    public function resolveByCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'size:6', 'regex:/^[A-Za-z0-9]{6}$/'],
        ]);

        $invitation = $this->inviteService->resolvePendingInviteByCode($data['code']);
        if (! $invitation) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired invitation'
            ], 404);
        }

        return $this->buildPreview($invitation);
    }

    public function joinByCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'size:6', 'regex:/^[A-Za-z0-9]{6}$/'],
        ]);

        $user = $request->user();
        $invitation = $this->inviteService->resolvePendingInviteByCode($data['code']);

        if (! $invitation) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired invitation'
            ], 404);
        }

        if ($invitation->inviter_id == $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot accept your own invite'
            ], 400);
        }

        $connection = $this->acceptInvitation($invitation, $user);

        return response()->json([
            'success' => true,
            'message' => 'Joined successfully',
            'connection' => $connection,
        ]);
    }

    public function accept(Request $request): JsonResponse
    {
        $data = $request->validate([
            'invite_id' => ['required', 'integer', 'exists:invitations,id'],
        ]);

        $user = $request->user();
        $invitation = Invitation::find($data['invite_id']);

        if (!$invitation) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid invitation'
            ], 404);
        }

        if ($invitation->status === Invitation::STATUS_ACCEPTED) {
            return response()->json([
                'success' => false,
                'message' => 'Invitation already accepted'
            ], 422);
        }

        if ($invitation->status !== Invitation::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'Invitation is not redeemable'
            ], 422);
        }

        if ($invitation->expires_at->isPast()) {
            return response()->json([
                'success' => false,
                'message' => 'Invitation expired'
            ], 422);
        }

        if ($invitation->inviter_id == $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot accept your own invite'
            ], 400);
        }

        $connection = $this->acceptInvitation($invitation, $user);

        return response()->json([
            'success' => true,
            'message' => 'Joined successfully',
            'connection' => $connection,
        ]);
    }

    public function reject(Request $request): JsonResponse
    {
        $data = $request->validate([
            'invite_id' => ['required', 'integer', 'exists:invitations,id'],
        ]);

        $user = $request->user();
        $invitation = Invitation::find($data['invite_id']);

        if (! $invitation) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid invitation'
            ], 404);
        }

        if ($invitation->status === Invitation::STATUS_REJECTED) {
            return response()->json([
                'success' => false,
                'message' => 'Invitation already rejected'
            ], 422);
        }

        if ($invitation->status === Invitation::STATUS_ACCEPTED) {
            return response()->json([
                'success' => false,
                'message' => 'Invitation already accepted'
            ], 422);
        }

        if ($invitation->status !== Invitation::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'Invitation is not redeemable'
            ], 422);
        }

        if ($invitation->expires_at->isPast()) {
            return response()->json([
                'success' => false,
                'message' => 'Invitation expired'
            ], 422);
        }

        if ($invitation->inviter_id == $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot reject your own invite'
            ], 400);
        }

        $invitation->update([
            'status' => Invitation::STATUS_REJECTED
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Invitation rejected successfully'
        ]);
    }

    public function connectedUsers(Request $request): JsonResponse
    {
        $user = $request->user();

        $connections = TeamConnection::query()
            ->where('user_id', $user->id)
            ->with(['connectedUser.profile.country'])
            ->latest()
            ->get();

        $connectedUsers = $connections
            ->map(function (TeamConnection $connection) {
                $connectedUser = $connection->connectedUser;

                if (! $connectedUser) {
                    return null;
                }

                return [
                    'connection_id' => $connection->id,
                    'connected_at' => $connection->created_at,
                    'connected_user' => [
                        'id' => $connectedUser->id,
                        'name' => $connectedUser->name,
                        'email' => $connectedUser->email,
                        'status' => $connectedUser->status,
                        'country' => $connectedUser->profile?->country?->name,
                        'gender' => $connectedUser->profile?->gender,
                        'birth_year' => $connectedUser->profile?->birth_year,
                        'onboarding_completed' => $connectedUser->profile?->onboarding_completed,
                    ],
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'success' => true,
            'count' => $connectedUsers->count(),
            'connections' => $connectedUsers,
        ]);
    }

    public function removeConnection(Request $request, int $connection): JsonResponse
    {
        $user = $request->user();
        $teamConnection = TeamConnection::find($connection);

        if (! $teamConnection) {
            return response()->json([
                'success' => false,
                'message' => 'Team member not found'
            ], 404);
        }

        if ($teamConnection->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to remove this team member'
            ], 403);
        }

        $removedUserId = $teamConnection->connected_user_id;

        DB::transaction(function () use ($teamConnection, $user, $removedUserId) {
            TeamConnection::query()
                ->where(function ($query) use ($user, $teamConnection) {
                    $query->where('user_id', $user->id)
                        ->where('connected_user_id', $teamConnection->connected_user_id);
                })
                ->orWhere(function ($query) use ($user, $teamConnection) {
                    $query->where('user_id', $teamConnection->connected_user_id)
                        ->where('connected_user_id', $user->id);
                })
                ->delete();
        });

        event(new TeamConnectionRemoved(
            inviterId: $user->id,
            connectedUserId: $removedUserId,
            payload: [
                'connection_id' => $connection,
                'inviter_id' => $user->id,
                'connected_user_id' => $removedUserId,
                'status' => 'removed',
                'removed_at' => now()->toIso8601String(),
            ],
        ));

        return response()->json([
            'success' => true,
            'message' => 'Team member removed successfully'
        ]);
    }

    private function buildPreview(Invitation $invitation): JsonResponse
    {
        $memberCount = TeamConnection::where('user_id', $invitation->inviter_id)->count() + 1;

        return response()->json([
            'success' => true,
            'invite_id' => $invitation->id,
            'inviter' => [
                'id' => $invitation->inviter->id,
                'name' => $invitation->inviter->name,
                'avatar' => $invitation->inviter->avatar ?? null,
            ],
            'team' => [
                'name' => $invitation->inviter->name . "'s Team",
                'member_count' => $memberCount,
            ],
            'code' => $invitation->code,
            'expires_at' => $invitation->expires_at,
        ]);
    }

    public function preview(string $token): JsonResponse
    {
        return $this->resolveByToken($token);
    }

    private function acceptInvitation(Invitation $invitation, User|Users $user): TeamConnectionDTO
    {
        DB::transaction(function () use ($invitation, $user) {
            $ids = [(int) $invitation->inviter_id, (int) $user->id];
            sort($ids);
            User::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $alreadyConnected = TeamConnection::where('user_id', $invitation->inviter_id)->where('connected_user_id', $user->id)->exists();
            if (! $alreadyConnected) {
                $membership = app(MembershipAccessService::class);
                foreach ($ids as $memberId) {
                    $member = User::query()->findOrFail($memberId);
                    $limit = $membership->snapshot($member)['limits']['team_connections'];
                    if (TeamConnection::where('user_id', $memberId)->count() >= $limit) {
                        throw ValidationException::withMessages(['connections' => 'A team member has reached their membership connection limit.']);
                    }
                }
            }

            TeamConnection::firstOrCreate([
                'user_id' => $invitation->inviter_id,
                'connected_user_id' => $user->id,
            ]);

            TeamConnection::firstOrCreate([
                'user_id' => $user->id,
                'connected_user_id' => $invitation->inviter_id,
            ]);

            $invitation->update([
                'status' => Invitation::STATUS_ACCEPTED,
            ]);
        });

        $invitation->load('inviter');

        $dto = new TeamConnectionDTO(
            inviteId: $invitation->id,
            inviterId: $invitation->inviter->id,
            inviterName: $invitation->inviter->name,
            inviterAvatar: $invitation->inviter->avatar,

            connectedUserId: $user->id,
            connectedUserName: $user->name,
            connectedUserAvatar: $user->avatar,

            teamMemberCount: TeamConnection::where('user_id', $invitation->inviter_id)->count() + 1,
            status: Invitation::STATUS_ACCEPTED,
            acceptedAt: now()->toIso8601String(),
        );

        event(new NewTeamConnectionAdded($dto));

        return $dto;
    }

    private function atConnectionLimit(User|Users $user): bool
    {
        $limit = app(MembershipAccessService::class)->snapshot($user)['limits']['team_connections'];
        return TeamConnection::where('user_id', $user->id)->count() >= $limit;
    }
}
