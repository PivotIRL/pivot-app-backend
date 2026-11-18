<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\GroupChallengeSession;
use App\Models\Hobby;
use App\Models\Subscription;
use App\Models\TeamConnection;
use App\Models\User;
use App\Models\Users;
use App\Organization\Models\OrganizationDashboardAccount;
use App\Repositories\AuthRepository;
use App\Repositories\UserRepository;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MobileUserCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public static function owners(): array
    {
        return ['canonical' => [User::class], 'legacy' => [Users::class]];
    }

    #[DataProvider('owners')]
    public function test_existing_tokens_preserve_profile_hobbies_activity_and_membership(string $owner): void
    {
        Event::fake();
        $user = $this->existingUser();
        $user->profile()->create(['weekly_goal_minutes' => 120]);
        $hobby = Hobby::create(['name' => 'Existing hobby']);
        $user->hobbies()->attach($hobby);
        $token = $owner::findOrFail($user->id)->createToken('mobile')->plainTextToken;

        $this->assertSame($owner, PersonalAccessToken::findToken($token)->tokenable_type);
        $this->bearer($token)->getJson('/api/v1/user/current-user')->assertOk()->assertJsonPath('user.id', $user->id);
        $this->bearer($token)->getJson('/api/v1/profile/me')->assertSuccessful();
        $this->bearer($token)->getJson('/api/v1/user/hobbies')->assertOk();
        $this->bearer($token)->getJson('/api/v1/access')->assertOk();
        $this->bearer($token)->postJson('/api/v1/user/hobbies', ['hobby_ids' => [$hobby->id]])->assertCreated();
        $this->bearer($token)->patchJson('/api/v1/user/status', ['status' => 'ready'])->assertOk();
        $this->bearer($token)->postJson('/api/v1/user/fcm-token', ['fcm_token' => 'compat-device'])->assertOk();
        $this->bearer($token)->postJson('/api/v1/user/activity', ['timezone' => 'Asia/Colombo'])->assertOk();

        $this->assertSame(120, $user->fresh()->profile->weekly_goal_minutes);
        $this->assertSame([$hobby->id], $user->hobbies()->pluck('hobbies.id')->all());
        $this->assertSame('ready', $user->fresh()->status);
        $this->assertSame('compat-device', $user->fresh()->fcm_token);
        $this->assertNotNull($user->fresh()->last_active_at);
    }

    #[DataProvider('owners')]
    public function test_real_tokens_can_reserve_resume_complete_and_abandon(string $owner): void
    {
        $user = $this->existingUser();
        $hobby = Hobby::create(['name' => 'Challenge hobby']);
        $challenge = Activity::create([
            'hobby_id' => $hobby->id, 'activity_title' => 'Existing activity',
            'duration_minutes' => '10', 'tier' => '1', 'energy_level' => 'Low',
        ]);
        $token = $owner::findOrFail($user->id)->createToken('mobile')->plainTextToken;
        $reservationId = (string) Str::uuid();
        $this->bearer($token)->postJson('/api/v1/challenge-reservations', [
            'challenge_id' => $challenge->id, 'reservation_id' => $reservationId, 'allocation' => 'included',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->bearer($token)->postJson("/api/v1/challenge-reservations/$reservationId/resume", [
            'challenge_id' => $challenge->id,
        ])->assertOk();

        // The same account may resume on a device using the other historical owner type.
        $otherOwner = $owner === User::class ? Users::class : User::class;
        $otherToken = $otherOwner::findOrFail($user->id)->createToken('mobile')->plainTextToken;
        $completion = ['completion_id' => (string) Str::uuid()];
        $this->bearer($otherToken)->postJson("/api/v1/challenge-reservations/$reservationId/complete", $completion)
            ->assertOk()->assertJsonPath('status', 'completed');
        $this->bearer($token)->postJson("/api/v1/challenge-reservations/$reservationId/complete", $completion)->assertOk();

        $abandonedId = (string) Str::uuid();
        $this->bearer($token)->postJson('/api/v1/challenge-reservations', [
            'challenge_id' => $challenge->id, 'reservation_id' => $abandonedId, 'allocation' => 'included',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->bearer($token)->postJson("/api/v1/challenge-reservations/$abandonedId/abandon")
            ->assertOk()->assertJsonPath('status', 'abandoned');
        $this->assertDatabaseCount('activities', 1);
    }

    public function test_existing_password_and_social_identity_keep_the_same_account(): void
    {
        $user = $this->existingUser();
        $originalHash = $user->password;
        $legacyToken = $user->createToken('mobile')->plainTextToken;
        $response = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('user.id', $user->id);
        $this->assertSame(User::class, PersonalAccessToken::findToken($response->json('token'))->tokenable_type);
        $this->assertSame($originalHash, $user->fresh()->password);
        $this->assertSame($originalHash, $user->fresh()->getAuthPassword());
        $this->bearer($legacyToken)->getJson('/api/v1/user/current-user')->assertOk();

        $repository = app(UserRepository::class);
        $google = $repository->findOrCreateByGoogle(['email' => $user->email, 'sub' => 'google-existing']);
        $apple = $repository->findOrCreateByApple(['email' => $user->email, 'sub' => 'apple-existing', 'name' => $user->name]);
        foreach ([$google, $apple] as $result) {
            $this->assertFalse($result['is_new']);
            $this->assertSame($user->id, $result['user']->id);
            $this->assertSame($originalHash, $result['user']->password);
        }
        $this->assertDatabaseCount('users', 1);
    }

    public function test_new_email_accounts_use_the_canonical_model_without_double_hashing(): void
    {
        DB::table('tier')->updateOrInsert(['id' => 1], ['name' => 'Free']);
        $user = app(AuthRepository::class)->createUser(['email' => 'new@example.com', 'name' => 'New', 'password' => 'password']);
        $this->assertSame(User::class, get_class($user));
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertSame(1, $user->subscription->tier_id);
    }

    public function test_beta_login_keeps_existing_device_and_account(): void
    {
        $user = $this->existingUser();
        $body = ['email' => $user->email, 'password' => 'password', 'device_id' => 'existing-device', 'platform' => 'android'];
        $this->postJson('/api/v1/beta-login', $body)->assertOk()->assertJsonPath('user.id', $user->id);
        $response = $this->postJson('/api/v1/beta-login', $body)->assertOk();
        $this->assertSame(User::class, PersonalAccessToken::findToken($response->json('token'))->tokenable_type);
        $this->assertDatabaseCount('device_fingerprints', 1);
        $this->postJson('/api/v1/beta-login', [...$body, 'device_id' => 'another-device'])->assertForbidden();
    }

    public function test_legacy_and_canonical_tokens_can_finish_a_group_challenge_together(): void
    {
        Event::fake();
        $host = $this->existingUser();
        $teammate = $this->existingUser();
        foreach ([$host, $teammate] as $user) {
            $user->update(['status' => 'ready', 'fcm_token' => null]);
        }
        TeamConnection::create(['user_id' => $host->id, 'connected_user_id' => $teammate->id]);
        TeamConnection::create(['user_id' => $teammate->id, 'connected_user_id' => $host->id]);
        $legacyToken = $host->createToken('mobile')->plainTextToken;
        $canonicalToken = User::findOrFail($teammate->id)->createToken('mobile')->plainTextToken;
        $sessionId = $this->bearer($legacyToken)->postJson('/api/v1/group-challenges/start', [
            'teammate_ids' => [$teammate->id],
        ])->assertCreated()->json('session.id');
        $this->bearer($canonicalToken)->postJson("/api/v1/group-challenges/$sessionId/accept")->assertOk();
        $this->bearer($legacyToken)->postJson("/api/v1/group-challenges/$sessionId/begin")->assertOk();
        $this->bearer($legacyToken)->postJson("/api/v1/group-challenges/$sessionId/pause")->assertOk();
        $this->bearer($legacyToken)->postJson("/api/v1/group-challenges/$sessionId/resume")->assertOk();
        $this->bearer($legacyToken)->postJson("/api/v1/group-challenges/$sessionId/complete")->assertOk();
        $this->bearer($canonicalToken)->postJson("/api/v1/group-challenges/$sessionId/complete")->assertOk();
        $this->assertSame('completed', GroupChallengeSession::findOrFail($sessionId)->status);
        $this->assertSame('ready', $host->fresh()->status);
        $this->assertSame('ready', $teammate->fresh()->status);
        $this->assertCount(1, $host->groupChallengeParticipants);
        $this->assertDatabaseCount('team_connections', 2);
    }

    public function test_subscription_and_organization_relationships_match_for_legacy_users(): void
    {
        $user = $this->existingUser();
        DB::table('tier')->updateOrInsert(['id' => 1], ['name' => 'Free']);
        $subscription = Subscription::create(['user_id' => $user->id, 'tier_id' => 1, 'active' => true]);
        $account = OrganizationDashboardAccount::create(['user_id' => $user->id, 'email' => $user->email]);
        foreach ([User::class, Users::class] as $owner) {
            $model = $owner::findOrFail($user->id);
            $this->assertSame($subscription->id, $model->subscription->id);
            $this->assertSame($account->id, $model->organizationDashboardAccount->id);
            $this->assertSame(0, $model->organizationRoleAssignments()->count());
            $this->assertSame(0, $model->organizationMemberships()->count());
        }
        $subscription->update(['active' => false]);
        foreach ([User::class, Users::class] as $owner) {
            $model = $owner::findOrFail($user->id);
            $this->assertNull($model->subscription);
            $this->assertSame($subscription->id, $model->latestSubscription->id);
            $this->assertCount(1, $model->subscriptions);
            $token = $model->createToken('mobile')->plainTextToken;
            $this->bearer($token)->getJson('/api/v1/user/current-user')->assertOk()->assertJsonPath('user.subscription', null);
        }
    }

    #[DataProvider('owners')]
    public function test_logout_revokes_both_mobile_token_types_only(string $owner): void
    {
        [$user, $legacy, $canonical, $dashboard, $unrelated] = $this->mixedTokens();
        $this->bearer($owner === Users::class ? $legacy : $canonical)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertNull(PersonalAccessToken::findToken($legacy));
        $this->assertNull(PersonalAccessToken::findToken($canonical));
        $this->assertNotNull(PersonalAccessToken::findToken($dashboard));
        $this->assertNotNull(PersonalAccessToken::findToken($unrelated));
        $this->assertNull($user->fresh()->fcm_token);
        $this->bearer($legacy)->getJson('/api/v1/user/current-user')->assertUnauthorized();
        $this->bearer($canonical)->getJson('/api/v1/user/current-user')->assertUnauthorized();
    }

    public function test_password_reset_revokes_both_mobile_token_types_and_preserves_dashboard(): void
    {
        [$user, $legacy, $canonical, $dashboard, $unrelated] = $this->mixedTokens();
        $resetToken = JWT::encode([
            'sub' => $user->email, 'scope' => 'password_reset', 'iat' => time(), 'exp' => time() + 600,
        ], config('app.key'), 'HS256');
        $this->postJson('/api/v1/reset-password', [
            'email' => $user->email, 'reset_token' => $resetToken,
            'password' => 'new-password', 'password_confirmation' => 'new-password',
        ])->assertOk();
        $this->assertNull(PersonalAccessToken::findToken($legacy));
        $this->assertNull(PersonalAccessToken::findToken($canonical));
        $this->assertNotNull(PersonalAccessToken::findToken($dashboard));
        $this->assertNotNull(PersonalAccessToken::findToken($unrelated));
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'new-password'])->assertOk();
    }

    #[DataProvider('owners')]
    public function test_account_deletion_cleans_up_both_mobile_token_types(string $owner): void
    {
        $user = $this->existingUser();
        $legacy = $user->createToken('mobile')->plainTextToken;
        $canonical = User::findOrFail($user->id)->createToken('mobile')->plainTextToken;
        $other = $this->existingUser()->createToken('mobile')->plainTextToken;
        $this->bearer($owner === Users::class ? $legacy : $canonical)->deleteJson('/api/v1/user/delete')->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertNull(PersonalAccessToken::findToken($legacy));
        $this->assertNull(PersonalAccessToken::findToken($canonical));
        $this->assertNotNull(PersonalAccessToken::findToken($other));
    }

    private function existingUser(): Users
    {
        // Insert the pre-consolidation data shape without invoking the new model casts.
        $id = DB::table('users')->insertGetId([
            'name' => 'Existing user', 'email' => Str::uuid().'@example.com',
            'password' => Hash::make('password'), 'provider' => 'email',
            'status' => 'not_ready', 'fcm_token' => 'existing-device',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return Users::findOrFail($id);
    }

    private function mixedTokens(): array
    {
        $user = $this->existingUser();
        $account = OrganizationDashboardAccount::create(['user_id' => $user->id, 'email' => $user->email]);
        return [
            $user,
            $user->createToken('mobile')->plainTextToken,
            User::findOrFail($user->id)->createToken('mobile')->plainTextToken,
            $account->createToken('organization-dashboard', ['organization-dashboard'])->plainTextToken,
            $this->existingUser()->createToken('mobile')->plainTextToken,
        ];
    }

    private function bearer(string $token): static
    {
        $this->app['auth']->forgetGuards();
        return $this->flushHeaders()->withToken($token);
    }
}
