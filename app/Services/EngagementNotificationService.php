<?php

namespace App\Services;

use App\Models\Users;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class EngagementNotificationService
{
    public function __construct(private FcmService $fcm) {}

    public function sendDue(): int
    {
        $sent = 0;
        Users::query()->whereNotNull('fcm_token')->where('fcm_token', '!=', '')
            ->chunkById(200, function ($users) use (&$sent) {
                foreach ($users as $user) {
                    if ($this->sendToUser($user)) $sent++;
                }
            });
        return $sent;
    }

    public function sendToUser(Users $user): bool
    {
        // Shared cache lock also protects manual runs on multiple servers.
        return (bool) Cache::lock('engagement-user-'.$user->id, 120)->get(function () use ($user) {
            $candidate = $this->candidate($user, CarbonImmutable::now('UTC'));
            if (! $candidate || ! $user->fcm_token) return false;
            [$key, $type, $title, $body] = $candidate;
            if (DB::table('engagement_notification_deliveries')->where('user_id', $user->id)->where('event_key', $key)->exists()) return false;
            $channel = $type === 'DAILY_CHALLENGE_REMINDER' ? 'pivot_daily_reminders' : 'pivot_engagement';
            $eventId = hash('sha256', $user->id.':'.$key);
            if (! $this->fcm->deliver($user->fcm_token, $title, $body, [
                'type' => $type, 'event_id' => $eventId,
            ], $channel, $eventId)) return false;
            DB::table('engagement_notification_deliveries')->insert([
                'user_id' => $user->id, 'event_key' => $key, 'sent_at' => now(),
            ]);
            return true;
        });
    }

    public function candidate(Users $user, CarbonImmutable $now): ?array
    {
        $registered = CarbonImmutable::parse($user->created_at, 'UTC');
        $access = DB::table('account_access')->where('user_id', $user->id)->first();
        // Never market a pre-billing trial to an already paying or expired account.
        $beforeBilling = $access && $access->access_status === 'trial'
            && $access->trial_ends_at && CarbonImmutable::parse($access->trial_ends_at, 'UTC')->gt($now);
        foreach ([2, 5] as $day) {
            $due = $registered->addDays($day);
            if ($beforeBilling && $now->gte($due) && $now->lt($due->addDay())) {
                return ['premium-day-'.$day, 'PREMIUM_DAY_'.$day, 'Make the most of your trial',
                    'Explore Pivot premium features before your trial ends. Try a new way to focus today.'];
            }
        }

        $lastActive = CarbonImmutable::parse($user->last_active_at ?? $user->last_login_at ?? $user->created_at, 'UTC');
        if ($lastActive->lte($now->subDays(max(1, config('engagement.inactive_days'))))) {
            return ['inactive-'.$lastActive->timestamp, 'INACTIVE_USER', 'Ready for a fresh start?',
                'A small step can make a big difference. Come back to Pivot and try a challenge today.'];
        }

        try {
            $local = $now->setTimezone($user->notification_timezone ?: 'UTC');
        } catch (\Exception) {
            $local = $now;
        }
        if ($local->hour !== config('engagement.daily_hour')) return null;
        $start = $local->startOfDay()->utc();
        $end = $local->startOfDay()->addDay()->utc();
        $completed = DB::table('challenge_logs')->where('user_id', $user->id)
            ->where('status', 'completed')->where('completed_at', '>=', $start)->where('completed_at', '<', $end)->exists();
        $completed = $completed || DB::table('challenge_quota_reservations')->where('user_id', $user->id)
            ->where('status', 'completed')->where('updated_at', '>=', $start)->where('updated_at', '<', $end)->exists();
        if ($completed) return null;
        return ['daily-'.$local->toDateString(), 'DAILY_CHALLENGE_REMINDER', 'Your daily challenge is waiting',
            'Take a little time for yourself. Open Pivot and complete today’s challenge.'];
    }
}
