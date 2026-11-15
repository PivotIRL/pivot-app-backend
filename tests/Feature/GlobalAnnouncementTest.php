<?php
namespace Tests\Feature;

use App\Models\{Admin, User};
use App\Services\FcmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GlobalAnnouncementTest extends TestCase {
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); Http::preventStrayRequests(); }
    private function member(): User {
        $user = User::create(['name' => 'Announcement test', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('test')]);
        Sanctum::actingAs($user); return $user;
    }
    private function admin(): void {
        $this->actingAs(Admin::create(['name' => 'Admin', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('test')]), 'admin');
    }
    private function publish(string $platform = 'both', bool $push = true, ?string $key = null): int {
        $this->post('/dashboard/notifications', ['request_key' => $key ?? (string) Str::uuid(),
            'title' => 'Hello Pivot', 'body' => 'Find your rhythm today.', 'platform' => $platform,
            'in_app' => '1', 'push' => $push ? '1' : '0', 'days' => '7'])->assertRedirect();
        return DB::table('global_announcements')->max('id');
    }
    public function test_authentication_and_validation(): void {
        $this->getJson('/api/v1/announcements?platform=android')->assertUnauthorized();
        $this->get('/dashboard/notifications')->assertRedirect('/dashboard/login');
        $this->member();
        $this->postJson('/api/v1/notification-devices', ['token' => 'test', 'platform' => 'web'])->assertUnprocessable();
        $this->postJson('/dashboard/notifications', [])->assertUnauthorized();
        $this->admin(); $this->get('/dashboard/notifications')->assertOk()->assertSee('Send a notification');
        $this->postJson('/dashboard/notifications', ['request_key' => (string) Str::uuid(), 'title' => 'a', 'body' => 'b',
            'platform' => 'both', 'in_app' => 0, 'push' => 0, 'days' => 7])->assertUnprocessable();
    }
    public function test_browser_form_publishes_in_app_message_and_renders_history(): void {
        $this->admin();
        $this->freezeTime();
        $id = $this->publish('android', false);
        $message = DB::table('global_announcements')->find($id);
        $this->assertSame(now()->addDays(7)->format('Y-m-d H:i:s'), $message->expires_at);
        $this->assertDatabaseCount('announcement_deliveries', 0);
        $this->get('/dashboard/notifications')->assertOk()->assertSee('Hello Pivot')
            ->assertSee('Active')->assertSee('Message preview')
            ->assertDontSee('No registered devices matched this audience.');
    }
    public function test_platform_targeting_read_isolation_and_withdrawal(): void {
        $first = $this->member(); $this->admin();
        $android = $this->publish('android', false); $ios = $this->publish('ios', false); $both = $this->publish('both', false);
        $this->getJson('/api/v1/announcements?platform=android')->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $android);
        $this->getJson('/api/v1/announcements?platform=ios')->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $ios);
        $this->postJson('/api/v1/announcements/'.$ios.'/read', ['platform' => 'android'])->assertNotFound();
        $this->postJson('/api/v1/announcements/'.$android.'/read', ['platform' => 'android'])->assertNoContent();
        $this->getJson('/api/v1/announcements?platform=android')->assertJsonCount(1, 'data');
        $this->member();
        $this->getJson('/api/v1/announcements?platform=android')->assertJsonCount(2, 'data');
        $this->delete('/dashboard/notifications/'.$both)->assertRedirect();
        $this->getJson('/api/v1/announcements?platform=android')->assertJsonCount(1, 'data');
        $this->travel(8)->days();
        $this->getJson('/api/v1/announcements?platform=android')->assertJsonCount(0, 'data');
    }
    public function test_push_targets_devices_and_is_not_resent_on_duplicate_submission_or_command(): void {
        $this->member();
        foreach (['android', 'ios'] as $platform) $this->postJson('/api/v1/notification-devices', ['token' => $platform.'-token', 'platform' => $platform])->assertNoContent();
        $this->admin(); $key = (string) Str::uuid(); $this->publish('android', true, $key); $this->publish('android', true, $key);
        $this->assertDatabaseCount('global_announcements', 1); $this->assertDatabaseCount('announcement_deliveries', 1);
        $this->mock(FcmService::class, fn ($m) => $m->shouldReceive('deliver')->once()->withArgs(fn ($token) => $token === 'android-token')->andReturn(true));
        $this->artisan('notifications:send-global')->assertSuccessful();
        $this->artisan('notifications:send-global')->assertSuccessful();
        $this->assertDatabaseHas('announcement_deliveries', ['status' => 'accepted']);
    }
    public function test_both_platforms_failure_and_withdrawn_pushes(): void {
        $this->member();
        foreach (['android', 'ios'] as $platform) $this->postJson('/api/v1/notification-devices', ['token' => $platform, 'platform' => $platform])->assertNoContent();
        $this->admin(); $this->publish();
        $this->assertDatabaseCount('announcement_deliveries', 2);
        $this->mock(FcmService::class, fn ($m) => $m->shouldReceive('deliver')->twice()->andReturn(false));
        $this->artisan('notifications:send-global')->assertSuccessful();
        $this->assertSame(2, DB::table('announcement_deliveries')->where('status', 'failed')->count());
        $id = $this->publish(); $this->delete('/dashboard/notifications/'.$id)->assertRedirect();
        $this->artisan('notifications:send-global')->assertSuccessful();
        $this->assertSame(2, DB::table('announcement_deliveries')->where('status', 'cancelled')->count());
    }
}
