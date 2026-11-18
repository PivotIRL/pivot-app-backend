<?php

namespace Tests\Unit;

use App\Models\Users;
use App\Services\EngagementNotificationService;
use App\Services\FcmService;
use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Isolated policy/transport checks: no production database or live FCM calls. */
class EngagementNotificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $container = new Container;
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        $container->instance('config', new Repository([
            'engagement' => ['inactive_days' => 3, 'daily_hour' => 14],
            'services' => ['firebase' => ['project_id' => 'test-project']],
        ]));
        Date::swap(new DateFactory);
        Cache::swap(new CacheRepository(new ArrayStore));
        DB::swap(Mockery::mock());
        Log::swap(Mockery::mock()->shouldIgnoreMissing());
        Http::swap(new Factory);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public static function trialCases(): array
    {
        return [
            'before day 2' => [47, 'trial', '2026-11-08', null],
            'day 2 begins' => [48, 'trial', '2026-11-08', 'PREMIUM_DAY_2'],
            'day 2 window ends' => [72, 'trial', '2026-11-08', null],
            'day 5 begins' => [120, 'trial', '2026-11-08', 'PREMIUM_DAY_5'],
            'paid account' => [48, 'active', '2026-11-08', null],
            'trial expired' => [120, 'trial', '2026-11-06', null],
        ];
    }

    #[DataProvider('trialCases')]
    public function test_trial_timing_and_billing_guard(int $hours, string $status, string $end, ?string $type): void
    {
        $now = CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC')->addHours($hours);
        $user = $this->user(['last_active_at' => $now->toDateTimeString()]);
        $this->access((object) ['access_status' => $status, 'trial_ends_at' => $end]);
        $candidate = $this->service()->candidate($user, $now);
        self::assertSame($type, $candidate[1] ?? null);
    }

    public function test_inactivity_threshold_and_episode_key(): void
    {
        $this->access(null, 3);
        $user = $this->user(['last_active_at' => '2026-11-02 12:00:00']);
        self::assertNull($this->service()->candidate($user, CarbonImmutable::parse('2026-11-05 11:59:59', 'UTC')));
        $first = $this->service()->candidate($user, CarbonImmutable::parse('2026-11-05 12:00:00', 'UTC'));
        $later = $this->service()->candidate($user, CarbonImmutable::parse('2026-11-09 12:00:00', 'UTC'));
        self::assertSame('INACTIVE_USER', $first[1]);
        self::assertSame($first[0], $later[0]);
    }

    public function test_daily_reminder_uses_local_time_and_skips_completed_challenges(): void
    {
        $this->access(null, 3);
        $user = $this->user(['last_active_at' => '2026-11-03 07:00:00', 'notification_timezone' => 'Asia/Colombo']);
        $service = $this->service();
        self::assertNull($service->candidate($user, CarbonImmutable::parse('2026-11-03 08:29:59', 'UTC')));
        $logs = Mockery::mock();
        $logs->shouldReceive('where')->andReturnSelf();
        $logs->shouldReceive('exists')->twice()->andReturn(false, true);
        DB::shouldReceive('table')->with('challenge_logs')->twice()->andReturn($logs);
        $reservations = Mockery::mock();
        $reservations->shouldReceive('where')->andReturnSelf();
        $reservations->shouldReceive('exists')->once()->andReturn(false);
        DB::shouldReceive('table')->with('challenge_quota_reservations')->once()->andReturn($reservations);
        $now = CarbonImmutable::parse('2026-11-03 08:30:00', 'UTC');
        self::assertSame('daily-2026-11-03', $service->candidate($user, $now)[0]);
        self::assertNull($service->candidate($user, $now));
    }

    public function test_fcm_background_payload_has_channel_tag_and_short_expiry(): void
    {
        Http::fake(['*' => Http::response(['name' => 'message-id'])]);
        self::assertTrue($this->transport()->deliver('device', 'Title', 'Body', ['type' => 'DAILY_CHALLENGE_REMINDER'], 'pivot_daily_reminders', 'event-id'));
        Http::assertSent(fn ($request) => $request['message']['notification']['title'] === 'Title'
            && $request['message']['android']['notification']['channel_id'] === 'pivot_daily_reminders'
            && $request['message']['android']['notification']['tag'] === 'event-id'
            && $request['message']['android']['ttl'] === '3600s');
    }

    public function test_fcm_failure_is_reported_for_retry(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        self::assertFalse($this->transport()->deliver('device', 'Title', 'Body'));
    }

    public function test_failed_send_is_retryable_and_successful_send_is_not_repeated(): void
    {
        $fcm = Mockery::mock(FcmService::class);
        $fcm->shouldReceive('deliver')->twice()->andReturn(false, true);
        $service = Mockery::mock(EngagementNotificationService::class, [$fcm])->makePartial();
        $service->shouldReceive('candidate')->times(3)->andReturn(['premium-day-2', 'PREMIUM_DAY_2', 'Title', 'Body']);
        $query = Mockery::mock();
        $query->shouldReceive('where')->andReturnSelf();
        $query->shouldReceive('exists')->times(3)->andReturn(false, false, true);
        $query->shouldReceive('insert')->once()->with(Mockery::on(fn ($row) => $row['user_id'] === 1 && $row['event_key'] === 'premium-day-2'))->andReturn(true);
        DB::shouldReceive('table')->with('engagement_notification_deliveries')->times(4)->andReturn($query);
        $user = $this->user(['fcm_token' => 'device']);
        self::assertFalse($service->sendToUser($user));
        self::assertTrue($service->sendToUser($user));
        self::assertFalse($service->sendToUser($user));
    }

    private function user(array $attributes = []): Users
    {
        return (new Users)->setDateFormat('Y-m-d H:i:s')->forceFill(array_merge([
            'id' => 1, 'created_at' => '2026-11-01 12:00:00', 'notification_timezone' => 'UTC',
        ], $attributes));
    }

    private function access(?object $access, int $times = 1): void
    {
        $query = Mockery::mock();
        $query->shouldReceive('where')->with('user_id', 1)->andReturnSelf();
        $query->shouldReceive('first')->times($times)->andReturn($access);
        DB::shouldReceive('table')->with('account_access')->times($times)->andReturn($query);
    }

    private function service(): EngagementNotificationService
    {
        return new EngagementNotificationService(new FcmService);
    }

    private function transport(): FcmService
    {
        return new class extends FcmService {
            protected function getAccessToken(): ?string { return 'test-token'; }
        };
    }
}
