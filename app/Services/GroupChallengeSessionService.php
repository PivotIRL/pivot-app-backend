<?php

namespace App\Services;

use App\Models\ActivityLogs;
use App\Models\User;
use App\Models\Users;
use App\Repositories\Contracts\GroupChallengeSessionRepositoryInterface;
use Illuminate\Support\Collection;

class GroupChallengeSessionService
{
    public function __construct(
        protected GroupChallengeSessionRepositoryInterface $repository
    ) {}

    public function getLeaderboard(): Collection
    {
        $dailyTotals = ActivityLogs::query()
            ->where('completed', true)
            ->get(['user_id', 'duration_minutes'])
            ->groupBy('user_id')
            ->map(fn(Collection $logs) => [
                'count' => $logs->count(),
                'duration' => (int) $logs->sum('duration_minutes'),
            ]);

        $groupTotals = [];
        foreach ($this->repository->getLeaderboardSessions() as $session) {
            if ($session->status !== 'completed' || ! $session->started_at || ! $session->ended_at) {
                continue;
            }

            $userIds = $session->participants
                ->where('invite_status', 'accepted')
                ->pluck('user_id')
                ->push($session->host_id)
                ->unique();
            $duration = $session->started_at->diffInMinutes($session->ended_at);

            foreach ($userIds as $userId) {
                $groupTotals[$userId]['count'] = ($groupTotals[$userId]['count'] ?? 0) + 1;
                $groupTotals[$userId]['duration'] = ($groupTotals[$userId]['duration'] ?? 0) + $duration;
            }
        }

        $users = User::query()
            ->get(['id', 'name'])
            ->merge(Users::query()->get(['id', 'name']))
            ->unique('id')
            ->values();

        return $users
            ->map(function (User|Users $user) use ($dailyTotals, $groupTotals) {
                $daily = $dailyTotals->get($user->id, ['count' => 0, 'duration' => 0]);
                $group = $groupTotals[$user->id] ?? ['count' => 0, 'duration' => 0];

                return [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    // Legacy clients used host_* for every ranked participant.
                    'host_id' => $user->id,
                    'host_name' => $user->name,
                    'total_challenge_count' => $daily['count'] + $group['count'],
                    'total_duration_minutes' => (int) ($daily['duration'] + $group['duration']),
                ];
            })
            ->sortByDesc('total_challenge_count')
            ->values();
    }
}
