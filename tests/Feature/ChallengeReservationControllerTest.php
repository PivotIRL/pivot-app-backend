<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ChallengePacksWebhook;
use App\Models\Hobby;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChallengeReservationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_included_reservations_are_limited_and_completion_is_idempotent(): void
    {
        $user = $this->makeUser();
        $challenge = $this->makeChallenge();
        Sanctum::actingAs($user);

        $first = $this->reserve($challenge->id)->json();
        $second = $this->reserve($challenge->id)->json();
        $this->reserve($challenge->id); // Free membership currently includes three slots.
        $this->postJson('/api/v1/challenge-reservations', [
            'challenge_id' => $challenge->id,
            'reservation_id' => (string) Str::uuid(),
            'allocation' => 'included',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(409);

        $completionId = (string) Str::uuid();
        $response = $this->postJson("/api/v1/challenge-reservations/{$first['reservation_id']}/complete", [
            'completion_id' => $completionId,
        ])->assertOk()->assertJsonPath('status', 'completed');

        $this->postJson("/api/v1/challenge-reservations/{$first['reservation_id']}/complete", [
            'completion_id' => $completionId,
        ])->assertOk()->assertExactJson($response->json());

        $this->postJson("/api/v1/challenge-reservations/{$first['reservation_id']}/complete", [
            'completion_id' => (string) Str::uuid(),
        ])->assertStatus(409);

        $this->postJson("/api/v1/challenge-reservations/{$second['reservation_id']}/abandon", [])
            ->assertOk()->assertJsonPath('status', 'abandoned');

        $this->reserve($challenge->id);
    }

    public function test_pack_slot_is_decremented_only_once_on_completion(): void
    {
        $user = $this->makeUser();
        $challenge = $this->makeChallenge();
        $transactionId = (string) Str::uuid();
        $pack = ChallengePacksWebhook::query()->create([
            'app_id' => 'pivot',
            'user_id' => $user->id,
            'price' => 1,
            'product_id' => '1_challenge_pack',
            'transaction_id' => $transactionId,
            'total' => 1,
            'remaining' => 1,
            'status' => 'unused',
        ]);
        Sanctum::actingAs($user);

        $reservation = $this->postJson('/api/v1/challenge-reservations', [
            'challenge_id' => $challenge->id,
            'reservation_id' => (string) Str::uuid(),
            'allocation' => 'pack',
            'pack_transaction_id' => $transactionId,
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()
            ->assertJsonPath('status', 'reserved')
            ->json();

        $completionId = (string) Str::uuid();
        $complete = fn () => $this->postJson("/api/v1/challenge-reservations/{$reservation['reservation_id']}/complete", [
            'completion_id' => $completionId,
        ])->assertOk();

        $response = $complete();
        $complete()->assertExactJson($response->json());
        $this->assertSame(0, $pack->fresh()->remaining);
        $this->assertSame('used', $pack->fresh()->status);
    }

    public function test_idempotency_key_replay_returns_the_same_reservation(): void
    {
        $user = $this->makeUser();
        $challenge = $this->makeChallenge();
        Sanctum::actingAs($user);
        $key = (string) Str::uuid();
        $reservationId = (string) Str::uuid();
        $body = [
            'challenge_id' => $challenge->id,
            'reservation_id' => $reservationId,
            'allocation' => 'included',
        ];

        $first = $this->postJson('/api/v1/challenge-reservations', $body, ['Idempotency-Key' => $key])
            ->assertCreated();
        $this->postJson('/api/v1/challenge-reservations', $body, ['Idempotency-Key' => $key])
            ->assertCreated()->assertExactJson($first->json());

        $this->postJson('/api/v1/challenge-reservations', [
            ...$body,
            'challenge_id' => $this->makeChallenge()->id,
        ], ['Idempotency-Key' => $key])->assertStatus(409);
    }

    private function makeUser(): User
    {
        return User::query()->create([
            'name' => 'Reservation User',
            'email' => fake()->unique()->safeEmail(),
            'password' => bcrypt('password'),
        ]);
    }

    private function makeChallenge(): Activity
    {
        $hobby = Hobby::query()->create(['name' => 'Reservation ' . Str::uuid()]);

        return Activity::query()->create([
            'hobby_id' => $hobby->id,
            'activity_title' => 'Accessible challenge',
            'duration_minutes' => '10',
            'tier' => '1',
            'energy_level' => 'Low',
        ]);
    }

    private function reserve(int $challengeId): TestResponse
    {
        return $this->postJson('/api/v1/challenge-reservations', [
            'challenge_id' => $challengeId,
            'reservation_id' => (string) Str::uuid(),
            'allocation' => 'included',
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated();
    }
}
