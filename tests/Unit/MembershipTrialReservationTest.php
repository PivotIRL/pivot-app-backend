<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\V1\MembershipController;
use App\Models\Users;
use App\Models\UserProfile;
use App\Services\Membership\MembershipAccessService;
use App\Services\Membership\MembershipProductCatalog;
use App\Services\Membership\MembershipReconciliationService;
use App\Services\Membership\MembershipTrialReservation;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class MembershipTrialReservationTest extends TestCase
{
    private function quote(): array
    {
        return ['attempt_id' => 'existing-attempt', 'expires_at' => now()->addMinutes(5)->toISOString(),
            'product_id' => 'standard_android_monthly', 'base_plan_id' => 'standard-android-monthly',
            'offer_id' => 'free-trial-standard-monthly', 'trial_access_package' => 'premium'];
    }

    private function attempts(array $quotes): void
    {
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('where')->andReturnSelf();
        $query->shouldReceive('first')->andReturnNull(); // New request key has no replay.
        $query->shouldReceive('get')->andReturn(collect(array_map(fn ($quote) => (object) [
            'attempt_id' => $quote['attempt_id'], 'quote' => json_encode($quote),
        ], $quotes)));
        DB::shouldReceive('table')->with('membership_attempts')->andReturn($query);
    }

    public function test_exact_account_reservation_can_be_resumed_with_a_new_request_key(): void
    {
        $quote = $this->quote();
        $this->attempts([$quote]);
        $response = $this->checkout('monthly', now()->addMinutes(5));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('existing-attempt', $response->getData(true)['attempt_id']);
        $this->assertSame('standard_android_monthly', $response->getData(true)['product_id']);
        $this->assertSame('monthly', $response->getData(true)['payment_path']);
        $this->assertSame($quote['offer_id'], $response->getData(true)['offer_id']);
    }

    public function test_reserved_trial_cannot_be_silently_changed_to_another_duration(): void
    {
        $this->attempts([$this->quote()]);
        $response = $this->checkout('annual', now()->addMinutes(5));
        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('trial_checkout_pending', $response->getData(true)['code']);
    }

    public function test_ambiguous_or_corrupt_reservations_never_choose_a_product(): void
    {
        $quote = $this->quote();
        $this->attempts([$quote, [...$quote, 'attempt_id' => 'another-attempt']]);
        $this->assertNull($this->app->make(MembershipTrialReservation::class)
            ->forUser(42, (object) ['status' => 'reserved']));
    }

    public function test_invalid_expiry_cannot_be_resumed(): void
    {
        $this->attempts([[...$this->quote(), 'expires_at' => 'not-a-date']]);
        $this->assertNull($this->app->make(MembershipTrialReservation::class)
            ->forUser(42, (object) ['status' => 'reserved']));
    }

    public function test_expired_reservation_is_not_released_when_store_verification_fails(): void
    {
        $this->attempts([]);
        $response = $this->checkout('monthly', now()->subMinute(), true);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('Verification unavailable', $response->getData(true)['message']);
    }

    private function checkout(string $path, $expiry, bool $verificationFails = false)
    {
        $user = (new Users())->forceFill(['id' => 42]);
        $user->setRelation('profile', (new UserProfile())->forceFill(['onboarding_completed' => true]));
        $trial = Mockery::mock(Builder::class);
        $trial->shouldReceive('where')->andReturnSelf();
        $trial->shouldReceive('first')->andReturn((object) ['status' => 'reserved', 'reservation_expires_at' => $expiry]);
        DB::shouldReceive('table')->with('membership_trials')->andReturn($trial);
        DB::shouldReceive('transaction')->never(); // Neither resume nor failure may create/release a trial.
        $access = Mockery::mock(MembershipAccessService::class);
        $access->shouldReceive('snapshot')->with($user)->andReturn(['access_status' => 'none']);
        $reconciliation = Mockery::mock(MembershipReconciliationService::class);
        if ($verificationFails) $reconciliation->shouldReceive('reconcile')->once()->with($user)
            ->andThrow(new \RuntimeException('Verification unavailable'));
        $request = Request::create('/api/v1/trial-checkouts', 'POST', [
            'selected_package' => 'standard', 'payment_path' => $path,
        ]);
        $request->headers->set('Idempotency-Key', '8b93b51c-e009-4ed1-b860-08c3491b5a24');
        $request->setUserResolver(fn () => $user);
        return (new MembershipController($access, new MembershipProductCatalog()))
            ->trialCheckout($request, $reconciliation);
    }
}
