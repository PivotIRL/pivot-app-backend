<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Hobby;
use App\Models\User;
use App\Models\Users;
use App\Services\Membership\MembershipAccessService;
use App\Services\Membership\MembershipProductCatalog;
use App\Services\Membership\MembershipReconciliationService;
use App\Services\Membership\MembershipTrialReservation;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MembershipController extends Controller
{
    public function __construct(
        private readonly MembershipAccessService $access,
        private readonly MembershipProductCatalog $catalog,
    ) {}

    public function access(Request $request)
    {
        return response()->json($this->access->snapshot($request->user()));
    }

    public function trialCheckout(Request $request, MembershipReconciliationService $reconciliation)
    {
        $data = $request->validate(['selected_package' => 'required|in:standard,premium', 'payment_path' => 'required|in:monthly,annual']);
        $this->requireIdempotencyKey($request);
        if ($replay = $this->replayAttempt($request, 'trial', $data)) return $replay;
        $user = $request->user();
        if (! $user->profile?->onboarding_completed) {
            return response()->json(['message' => 'Completed onboarding is required before starting a trial.'], 409);
        }
        if ($error = $this->releaseExpiredTrialReservation($user, $reconciliation)) return $error;
        $trial = DB::table('membership_trials')->where('user_id', $user->id)->first();
        $access = $this->access->snapshot($user);
        if (in_array($access['access_status'], ['active', 'trial'], true)) {
            return response()->json(['message' => 'An account with current access cannot enroll in a new trial.'], 409);
        }
        if ($trial?->status === 'reserved') {
            $pending = app(MembershipTrialReservation::class)->forUser($user->id, $trial);
            if ($pending && $pending['selected_package'] === $data['selected_package']
                && $pending['payment_path'] === $data['payment_path']
                && now()->lessThan($pending['expires_at'])) {
                return response()->json($pending);
            }
            return response()->json(['message' => 'Resume the existing trial checkout before choosing another plan.',
                'code' => 'trial_checkout_pending'], 409);
        }
        if ($trial && $trial->status !== 'eligible') {
            return response()->json(['message' => 'The account trial is not available.'], 409);
        }
        $product = $this->catalog->checkoutProduct($data['selected_package'], $data['payment_path']);
        $offer = $this->catalog->trialOfferId($data['selected_package'], $data['payment_path']);

        return $this->createAttempt($request, 'trial', $data, [
            'product_id' => $product['store_product_id'],
            'base_plan_id' => $product['base_plan_id'],
            'offer_id' => $offer,
            'trial_access_package' => config('membership.full_trial_premium', true) ? 'premium' : $data['selected_package'],
        ], function () use ($user) {
            $reservation = DB::table('membership_trials')->where('user_id', $user->id)->lockForUpdate()->first();
            if ($reservation && $reservation->status !== 'eligible') {
                throw ValidationException::withMessages(['trial' => 'The account trial is not available.']);
            }
            DB::table('membership_trials')->updateOrInsert(['user_id' => $user->id], [
                'status' => 'reserved', 'reserved_at' => now(), 'reservation_expires_at' => now()->addMinutes(10), 'updated_at' => now(), 'created_at' => now(),
            ]);
        });
    }

    public function startTrial(Request $request, MembershipReconciliationService $reconciliation)
    {
        $data = $request->validate(['selected_package' => 'required|in:standard,premium', 'payment_path' => 'required|in:lifetime']);
        $key = $this->requireIdempotencyKey($request);
        if ($replay = $this->replayAttempt($request, 'lifetime_trial', $data)) return $replay;
        $user = $request->user();
        if (! $user->profile?->onboarding_completed) {
            return response()->json(['message' => 'Completed onboarding is required before starting a trial.'], 409);
        }
        if ($error = $this->releaseExpiredTrialReservation($user, $reconciliation)) return $error;
        $fingerprint = hash('sha256', json_encode($data));
        try {
            DB::transaction(function () use ($user, $key, $data, $fingerprint) {
                $user = Users::query()->lockForUpdate()->findOrFail($user->id);
                $existing = DB::table('membership_attempts')->where('user_id', $user->id)->where('operation', 'lifetime_trial')->where('idempotency_key', $key)->first();
                if ($existing) {
                    if ($existing->request_fingerprint !== $fingerprint) throw ValidationException::withMessages(['Idempotency-Key' => 'This key was already used with a different request.']);
                    return;
                }
                $trial = DB::table('membership_trials')->where('user_id', $user->id)->lockForUpdate()->first();
                $access = $this->access->snapshot($user);
                if ($trial && $trial->status !== 'eligible') throw ValidationException::withMessages(['trial' => 'The account trial has already been reserved or consumed.']);
                if (in_array($access['access_status'], ['active', 'trial'], true)) throw ValidationException::withMessages(['access' => 'An account with current access cannot start another trial.']);
                $now = now()->utc();
                $ends = $now->copy()->addDays(7);
                DB::table('membership_trials')->updateOrInsert(['user_id' => $user->id], [
                    'status' => 'consumed', 'started_at' => $now, 'ends_at' => $ends, 'source' => 'account_trial', 'updated_at' => $now, 'created_at' => $now,
                ]);
                $trialPackage = config('membership.full_trial_premium', true) ? 'premium' : $data['selected_package'];
                DB::table('account_access')->updateOrInsert(['user_id' => $user->id], [
                    'selected_package' => $data['selected_package'], 'effective_package' => $trialPackage, 'payment_path' => $data['payment_path'],
                    'access_status' => 'trial', 'access_source' => 'account_trial', 'product_id' => null,
                    'trial_ends_at' => $ends, 'access_ends_at' => $ends, 'updated_at' => $now, 'created_at' => $now,
                ]);
                DB::table('membership_attempts')->insert([
                    'user_id' => $user->id, 'attempt_id' => (string) Str::uuid(), 'operation' => 'lifetime_trial', 'idempotency_key' => $key,
                    'request_fingerprint' => $fingerprint, 'quote' => json_encode($data), 'status' => 'completed', 'created_at' => $now, 'updated_at' => $now,
                ]);
            });
        } catch (QueryException $e) {
            return response()->json(['message' => 'Trial attempt conflicts with another request.'], 409);
        }

        return response()->json($this->access->snapshot($user->fresh()));
    }

    public function categories(Request $request)
    {
        return response()->json($this->access->categories($request->user()));
    }

    public function updateCategories(Request $request)
    {
        $user = $request->user();
        $snapshot = $this->access->snapshot($user);
        if ($snapshot['effective_package'] === null || ! in_array($snapshot['access_status'], ['active', 'trial'], true)) {
            return response()->json(['message' => 'Active membership is required to update categories.'], 409);
        }
        $count = $snapshot['limits']['category_count'];
        $data = $request->validate(['category_ids' => 'required|array|min:1'.($count === null ? '' : '|size:'.$count), 'category_ids.*' => 'required|string|distinct']);
        $ids = array_map('intval', $data['category_ids']);
        $known = Hobby::query()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $mandatory = Hobby::query()->whereRaw('LOWER(name) = ?', ['micro-movement'])->value('id');
        if (count($known) !== count($ids) || ($mandatory && ! in_array((int) $mandatory, $ids, true))) {
            throw ValidationException::withMessages(['category_ids' => 'Choose the required number of known categories and include Micro-Movement.']);
        }
        DB::transaction(function () use ($user, $ids) {
            DB::table('membership_categories')->where('user_id', $user->id)->delete();
            $now = now();
            DB::table('membership_categories')->insert(array_map(fn ($id) => ['user_id' => $user->id, 'hobby_id' => $id, 'created_at' => $now, 'updated_at' => $now], $ids));
        });
        return response()->json($this->access->snapshot($user));
    }

    public function acknowledge(Request $request)
    {
        $data = $request->validate(['version' => 'required|string|max:100']);
        DB::table('membership_announcement_acknowledgements')->updateOrInsert(
            ['user_id' => $request->user()->id, 'version' => $data['version']],
            ['acknowledged_at' => now(), 'updated_at' => now(), 'created_at' => now()]
        );
        return response()->noContent();
    }

    public function reconcile(Request $request, MembershipReconciliationService $reconciliation)
    {
        $data = $request->validate(['attempt_id' => 'nullable|string|max:100']);
        if (! empty($data['attempt_id'])) {
            $owned = DB::table('membership_attempts')->where('attempt_id', $data['attempt_id'])->where('user_id', $request->user()->id)->exists();
            if (! $owned) return response()->json(['message' => 'Attempt not found for this account.'], 404);
        }
        try {
            $reconciliation->reconcile($request->user());
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }
        return response()->json($this->access->snapshot($request->user()->fresh()));
    }

    public function cancelTrialCheckout(Request $request, MembershipReconciliationService $reconciliation)
    {
        $user = $request->user();
        try {
            $reconciliation->reconcile($user);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        DB::transaction(function () use ($user) {
            $lockedUser = Users::query()->lockForUpdate()->findOrFail($user->id);
            $access = $this->access->snapshot($lockedUser);
            if (in_array($access['access_status'], ['active', 'trial', 'billing_restricted'], true)) {
                abort(409, 'A verified purchase already exists. Refresh or restore your membership.');
            }
            $storePurchase = DB::table('verified_purchases')->where('user_id', $user->id)
                ->where('environment', config('membership.store_environment', 'production'))->whereNull('revoked_at')
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists();
            if ($storePurchase) abort(409, 'A current store purchase needs reconciliation before cancelling checkout.');
            app(MembershipTrialReservation::class)->cancelUnpaidAfterVerification($user->id);
        });

        return response()->json($this->access->snapshot($user->fresh()));
    }

    public function purchaseCheckout(Request $request, MembershipReconciliationService $reconciliation)
    {
        $data = $request->validate(['selected_package' => 'required|in:standard,premium', 'payment_path' => 'required|in:monthly,annual,lifetime']);
        $this->requireIdempotencyKey($request);
        if ($replay = $this->replayAttempt($request, 'purchase', $data)) return $replay;
        $access = $this->access->snapshot($request->user());
        if (in_array($access['access_status'], ['active', 'trial'], true)) {
            return response()->json(['message' => 'Use the change checkout for an account with current access.'], 409);
        }
        if ($error = $this->releaseExpiredTrialReservation($request->user(), $reconciliation)) return $error;
        $trial = DB::table('membership_trials')->where('user_id', $request->user()->id)->first();
        if ($trial?->status === 'reserved' && $trial->reservation_expires_at
            && now()->lessThan($trial->reservation_expires_at)) {
            return response()->json(['message' => 'Resolve the pending trial checkout before starting another purchase.'], 409);
        }
        $product = $this->catalog->checkoutProduct($data['selected_package'], $data['payment_path']);
        return $this->createAttempt($request, 'purchase', $data, ['product_id' => $product['store_product_id'], 'base_plan_id' => $product['base_plan_id'], 'offer_id' => null, 'trial_access_package' => null]);
    }

    public function changeCheckout(Request $request)
    {
        $data = $request->validate(['selected_package' => 'required|in:standard,premium', 'payment_path' => 'required|in:monthly,annual,lifetime']);
        $this->requireIdempotencyKey($request);
        if ($replay = $this->replayAttempt($request, 'change', $data)) return $replay;
        $scheduled = DB::table('membership_attempts')->where('user_id', $request->user()->id)->where('operation', 'change')->where('status', 'scheduled')->exists();
        if ($scheduled) return response()->json(['message' => 'A scheduled store change must take effect before another change can be quoted.'], 409);
        $recurringCount = DB::table('verified_purchases')->where('user_id', $request->user()->id)
            ->where('environment', config('membership.store_environment', 'production'))
            ->whereIn('payment_path', ['monthly', 'annual'])->whereNull('revoked_at')
            ->where(function ($query) { $query->whereNull('expires_at')->orWhere('expires_at', '>', now()); })->count();
        if ($recurringCount > 1) return response()->json(['message' => 'Multiple active recurring purchases require reconciliation before a change.'], 409);
        $snapshot = $this->access->snapshot($request->user());
        if (! in_array($snapshot['access_status'], ['active', 'trial'], true) || ! $snapshot['product_id']) return response()->json(['message' => 'A verified current purchase is required to quote a change.'], 409);
        if ($snapshot['access_status'] === 'trial') return response()->json(['message' => 'Trial replacement is not supported.'], 409);
        if ($snapshot['access_source'] !== 'store') return response()->json(['message' => 'Reconcile the current store purchase before quoting a change.'], 409);
        $sourceProduct = explode(':', $snapshot['product_id'])[0];
        $sourceChoice = $this->catalog->resolve([$snapshot['product_id']], null, $snapshot['selected_package'])
            ?? $this->catalog->resolve([$snapshot['product_id']], $snapshot['payment_path'], $snapshot['selected_package']);
        if (! $sourceChoice) return response()->json(['message' => 'The current store product could not be mapped. Reconcile access and retry.'], 409);
        $sourcePlan = $sourceChoice['base_plan_id'];
        $sourcePurchase = DB::table('verified_purchases')->where('user_id', $request->user()->id)
            ->where('environment', config('membership.store_environment', 'production'))
            ->where('product_id', $sourceProduct)
            ->where('payment_path', $snapshot['payment_path'])
            ->when($sourcePlan, fn ($query) => $query->where('base_plan_id', $sourcePlan))
            ->whereNull('revoked_at')
            ->where(function ($query) { $query->whereNull('expires_at')->orWhere('expires_at', '>', now()); })
            ->exists();
        if (! $sourcePurchase) return response()->json(['message' => 'The current store purchase could not be verified. Reconcile access and retry.'], 409);
        $targetProduct = $this->catalog->checkoutProduct($data['selected_package'], $data['payment_path']);
        $currentPath = $snapshot['payment_path'];
        $mode = $this->replacementMode($snapshot['selected_package'], $currentPath, $data['selected_package'], $data['payment_path']);
        if (! $mode) return response()->json(['message' => 'This membership transition is not supported.'], 422);
        if ($data['payment_path'] === 'lifetime' && $currentPath !== 'lifetime') {
            $renewalStopped = DB::table('verified_purchases')->where('user_id', $request->user()->id)
                ->where('environment', config('membership.store_environment', 'production'))
                ->where('product_id', $sourceProduct)->where('renewal_status', 'cancelled')
                ->whereNull('revoked_at')->exists();
            if (! $renewalStopped) return response()->json(['message' => 'Verified renewal cancellation is required before a lifetime conversion.'], 409);
        }
        if ($currentPath === 'lifetime' && $data['payment_path'] === 'lifetime' && $data['selected_package'] === 'premium') {
            $activeRecurring = DB::table('verified_purchases')->where('user_id', $request->user()->id)
                ->where('environment', config('membership.store_environment', 'production'))
                ->whereIn('payment_path', ['monthly', 'annual'])->whereNull('revoked_at')
                ->where(function ($query) { $query->whereNull('expires_at')->orWhere('expires_at', '>', now()); })->exists();
            if ($activeRecurring) return response()->json(['message' => 'An active recurring subscription prevents a lifetime upgrade.'], 409);
        }
        $quote = ['selected_package' => $data['selected_package'], 'payment_path' => $data['payment_path'], 'old_product_id' => $snapshot['product_id'], 'product_id' => $targetProduct['store_product_id'], 'base_plan_id' => $targetProduct['base_plan_id'], 'replacement_mode' => $mode, 'renewal_stopped' => $currentPath !== 'lifetime' && $data['payment_path'] === 'lifetime'];
        return $this->createAttempt($request, 'change', $data, $quote);
    }

    private function replacementMode(string $currentPackage, string $currentPath, string $targetPackage, string $targetPath): ?string
    {
        if ($currentPackage === $targetPackage && $currentPath === $targetPath) return null;

        if ($currentPath === 'lifetime') {
            return $targetPath === 'lifetime' && $currentPackage === 'standard' && $targetPackage === 'premium'
                ? 'ONE_TIME'
                : null;
        }

        if ($targetPath === 'lifetime') {
            return $targetPackage === $currentPackage || $targetPackage === 'premium' ? 'ONE_TIME' : null;
        }

        if ($currentPackage === 'premium' && $targetPackage === 'standard') return 'DEFERRED';
        if ($currentPackage === $targetPackage) {
            if ($currentPath === 'annual' && $targetPath === 'monthly') return 'DEFERRED';
            if ($currentPath === 'monthly' && $targetPath === 'annual') return 'CHARGE_FULL_PRICE';
            return null;
        }
        if ($currentPackage === 'standard' && $targetPackage === 'premium') {
            return $currentPath === $targetPath ? 'CHARGE_PRORATED_PRICE' : 'CHARGE_FULL_PRICE';
        }

        return null;
    }

    private function releaseExpiredTrialReservation(User|Users $user, MembershipReconciliationService $reconciliation)
    {
        $trial = DB::table('membership_trials')->where('user_id', $user->id)->first();
        if ($trial?->status !== 'reserved' || ! $trial->reservation_expires_at
            || now()->lessThan($trial->reservation_expires_at)) return null;

        try {
            $reconciliation->reconcile($user);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        DB::transaction(function () use ($user) {
            Users::query()->lockForUpdate()->findOrFail($user->id);
            $reservation = DB::table('membership_trials')->where('user_id', $user->id)->lockForUpdate()->first();
            if ($reservation?->status === 'reserved' && $reservation->reservation_expires_at
                && now()->greaterThanOrEqualTo($reservation->reservation_expires_at)) {
                DB::table('membership_trials')->where('user_id', $user->id)->update([
                    'status' => 'eligible',
                    'reserved_at' => null,
                    'reservation_expires_at' => null,
                    'updated_at' => now(),
                ]);
            }
        });

        return null;
    }

    /** Recover account-owned attempts even after reinstall/older clients lost their key. */
    public function recoverCheckout(Request $request, MembershipReconciliationService $reconciliation)
    {
        $data = $request->validate(['idempotency_key' => 'nullable|uuid']);
        try { $reconciliation->reconcile($request->user()); }
        catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 503); }
        $attempts = DB::table('membership_attempts')->where('user_id', $request->user()->id)
            ->whereIn('operation', ['purchase', 'change'])->where(function ($query) use ($data) {
                $query->whereIn('status', ['pending', 'scheduled']);
                if (!empty($data['idempotency_key'])) $query->orWhere('idempotency_key', $data['idempotency_key']);
            })
            ->orderBy('id')->get()->map(fn ($attempt) => [
                'attempt_id' => $attempt->attempt_id, 'operation' => $attempt->operation,
                'status' => $attempt->status, 'idempotency_key' => $attempt->idempotency_key,
                'quote' => json_decode($attempt->quote, true),
            ]);
        return response()->json(['attempts' => $attempts]);
    }

    public function cancelCheckout(Request $request, MembershipReconciliationService $reconciliation)
    {
        $data = $request->validate(['attempt_id' => 'required|uuid']);
        $user = $request->user();
        $owned = DB::table('membership_attempts')->where('user_id', $user->id)
            ->where('attempt_id', $data['attempt_id'])->whereIn('operation', ['purchase', 'change'])->exists();
        if (! $owned) return response()->json(['message' => 'Attempt not found.'], 404);
        // A cancelled Play sheet is not proof of no payment. Verify with the store first.
        try { $reconciliation->reconcile($user); }
        catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 503); }
        return DB::transaction(function () use ($user, $data) {
            Users::query()->lockForUpdate()->findOrFail($user->id);
            $attempt = DB::table('membership_attempts')->where('user_id', $user->id)
                ->where('attempt_id', $data['attempt_id'])->lockForUpdate()->first();
            if (in_array($attempt->status, ['completed', 'scheduled'], true) || $attempt->verified_transaction_id) {
                abort(409, 'A verified or scheduled payment cannot be cancelled here. Refresh or restore purchases.');
            }
            if ($attempt->status === 'pending') {
                app(\App\Services\Membership\MembershipAttemptStatus::class)->transition(
                    (int) $attempt->id, 'cancelled', null, 'complete_revenuecat_reconciliation_found_no_matching_purchase');
            }
            return response()->json(['attempt_id' => $attempt->attempt_id,
                'status' => $attempt->status === 'failed' ? 'failed' : 'cancelled']);
        });
    }

    public function attemptStatus(Request $request)
    {
        $data = $request->validate(['attempt_id' => 'required|string|max:100']);
        $attempt = DB::table('membership_attempts')->where('attempt_id', $data['attempt_id'])->where('user_id', $request->user()->id)->first();
        if (! $attempt) return response()->json(['message' => 'Attempt not found.'], 404);
        return response()->json(['attempt_id' => $attempt->attempt_id, 'status' => $attempt->status]);
    }

    private function createAttempt(Request $request, string $operation, array $data, array $quote, ?callable $reserve = null)
    {
        $key = $request->header('Idempotency-Key');
        $user = $request->user();
        $fingerprint = hash('sha256', json_encode($data));
        $existing = DB::table('membership_attempts')->where('user_id', $user->id)->where('operation', $operation)->where('idempotency_key', $key)->first();
        if ($existing) {
            if ($existing->request_fingerprint !== $fingerprint) return response()->json(['message' => 'Idempotency key was reused with a different request.'], 409);
            return $this->replayAttempt($request, $operation, $data);
        }
        $now = now()->utc();
        $attemptId = (string) Str::uuid();
        $expiresAt = $now->copy()->addMinutes(10);
        $response = ['attempt_id' => $attemptId, 'expires_at' => $expiresAt->toISOString(), ...$data, ...$quote];
        try {
            DB::transaction(function () use ($user, $operation, $key, $fingerprint, $response, $expiresAt, $now, $reserve) {
                $lockedUser = Users::query()->lockForUpdate()->findOrFail($user->id);
                $access = $this->access->snapshot($lockedUser);
                if ($operation === 'trial' && in_array($access['access_status'], ['active', 'trial'], true)) {
                    abort(409, 'An account with current access cannot enroll in a new trial.');
                }
                if ($operation === 'purchase' && in_array($access['access_status'], ['active', 'trial'], true)) {
                    abort(409, 'Use the change checkout for an account with current access.');
                }
                if ($operation === 'purchase') {
                    $trial = DB::table('membership_trials')->where('user_id', $user->id)->first();
                    if ($trial?->status === 'reserved' && $trial->reservation_expires_at
                        && now()->lessThan($trial->reservation_expires_at)) {
                        abort(409, 'Resolve the pending trial checkout before starting another purchase.');
                    }
                }
                if ($operation === 'change'
                    && ($access['access_status'] !== 'active' || $access['access_source'] !== 'store'
                        || ($response['old_product_id'] ?? null) !== $access['product_id'])) {
                    abort(409, 'The current membership changed. Request a new change quote.');
                }
                if (in_array($operation, ['purchase', 'change'], true)) {
                    $conflict = DB::table('membership_attempts')->where('user_id', $user->id)
                        ->whereIn('operation', ['purchase', 'change'])->whereIn('status', ['pending', 'scheduled'])->exists();
                    if ($conflict) throw ValidationException::withMessages(['attempt' => 'Resolve the existing checkout attempt first.']);
                }
                DB::table('membership_attempts')->insert([
                    'user_id' => $user->id, 'attempt_id' => $response['attempt_id'], 'operation' => $operation,
                    'idempotency_key' => $key, 'request_fingerprint' => $fingerprint, 'quote' => json_encode($response),
                    'status' => 'pending', 'expires_at' => $expiresAt, 'created_at' => $now, 'updated_at' => $now,
                ]);
                if ($reserve) $reserve();
            });
        } catch (QueryException $e) {
            $retry = DB::table('membership_attempts')->where('user_id', $user->id)->where('operation', $operation)->where('idempotency_key', $key)->first();
            if ($retry && $retry->request_fingerprint === $fingerprint) return $this->replayAttempt($request, $operation, $data);
            return response()->json(['message' => 'Conflicting membership attempt.'], 409);
        }
        return response()->json($response, 201);
    }

    private function requireIdempotencyKey(Request $request): string
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || ! Str::isUuid($key)) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'A valid UUID idempotency key is required.']);
        }
        return $key;
    }

    private function replayAttempt(Request $request, string $operation, array $data)
    {
        $key = $request->header('Idempotency-Key');
        $attempt = DB::table('membership_attempts')->where('user_id', $request->user()->id)
            ->where('operation', $operation)->where('idempotency_key', $key)->first();
        if (! $attempt) return null;
        if ($attempt->request_fingerprint !== hash('sha256', json_encode($data))) {
            return response()->json(['message' => 'Idempotency key was reused with a different request.'], 409);
        }
        if (in_array($attempt->status, ['cancelled', 'failed'], true)) return response()->json(['message' => 'This checkout was retired. Recover checkout before retrying.'], 409);
        if ($attempt->status === 'pending' && $attempt->expires_at && now()->greaterThanOrEqualTo($attempt->expires_at)) {
            return response()->json(['message' => 'This checkout quote has expired. Create a new attempt to review current terms.'], 409);
        }
        if ($operation === 'change' && $attempt->status === 'pending') {
            $quote = json_decode($attempt->quote, true) ?: [];
            if (($quote['old_product_id'] ?? null) !== $this->access->snapshot($request->user())['product_id']) {
                return response()->json(['message' => 'The current membership changed. Request a new change quote.'], 409);
            }
        }
        if ($operation === 'lifetime_trial') return response()->json($this->access->snapshot($request->user()));
        return response()->json(json_decode($attempt->quote, true));
    }
}
