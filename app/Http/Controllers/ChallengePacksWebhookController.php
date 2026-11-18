<?php

namespace App\Http\Controllers;

use App\Models\ChallengePacksWebhook;
use App\Models\Subscription;
use App\Models\Users;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ChallengePacksWebhookController extends Controller
{
    public function handleChallengePackWebhook(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request->header('Authorization'))) {
            return response()->json(['message' => 'Unauthorized webhook request.'], 401);
        }

        $data = $request->validate([
            'event' => 'required|array',
            'event.id' => 'nullable|string|max:255',
            'event.type' => 'required|string|max:100',
            'event.app_id' => 'required|string|max:255',
            'event.app_user_id' => 'required|string|max:255',
            'event.original_app_user_id' => 'nullable|string|max:255',
            'event.aliases' => 'nullable|array',
            'event.aliases.*' => 'string|max:255',
            'event.transaction_id' => 'required|string|max:255',
            'event.product_id' => 'required|string|max:255',
            'event.price' => 'required|numeric',
            'event.environment' => 'nullable|string|max:50',
            'event.store' => 'nullable|string|max:50',
        ]);
        $event = $data['event'];

        if (strtoupper($event['type']) !== 'NON_RENEWING_PURCHASE') {
            return response()->json(['message' => 'Challenge pack event ignored.'], 200);
        }

        if (strtolower((string) ($event['environment'] ?? 'production')) !== strtolower((string) config('membership.store_environment', 'production'))) {
            return response()->json(['message' => 'Challenge pack environment is isolated from this backend.'], 200);
        }

        $allowedProducts = [
            '1_challenge_pack',
            '3_challenge_pack',
        ];

        if (!in_array($event['product_id'], $allowedProducts, true)) {
            return response()->json([], 200);
        }

        $total = match ($event['product_id']) {
            '1_challenge_pack' => 1,
            '3_challenge_pack' => 3,
            default => 0,
        };

        $user = $this->resolveUser($event);
        if (! $user) return response()->json(['message' => 'Unable to resolve the RevenueCat user.'], 422);

        $created = DB::transaction(function () use ($event, $user, $total) {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            $existing = ChallengePacksWebhook::query()->where('transaction_id', $event['transaction_id'])->first();
            if ($existing) {
                if ((int) $existing->user_id !== (int) $user->id) abort(409, 'Challenge pack transaction is already linked to another account.');

                return false;
            }

            ChallengePacksWebhook::query()->create([
                'app_id' => $event['app_id'],
                'user_id' => $user->id,
                'transaction_id' => $event['transaction_id'],
                'price' => $event['price'],
                'product_id' => $event['product_id'],
                'total' => $total,
                'remaining' => $total,
                'status' => 'unused',
                'environment' => $event['environment'] ?? 'production',
                'store' => $event['store'] ?? null,
                'type' => $event['type'],
            ]);

            return true;
        });

        return response()->json([
            'message' => $created ? 'Challenge pack stored successfully.' : 'Challenge pack event already received.',
        ], 200);
    }

    private function isAuthorized(?string $authorizationHeader): bool
    {
        $secret = (string) config('services.revenuecat.webhook_secret', '');

        return $secret !== '' && $authorizationHeader !== null
            && hash_equals('Bearer ' . $secret, trim($authorizationHeader));
    }

    private function resolveUser(array $event): ?Users
    {
        $candidateIds = array_filter([
            $event['app_user_id'] ?? null,
            $event['original_app_user_id'] ?? null,
            ...Arr::wrap($event['aliases'] ?? []),
        ], static fn ($value) => is_string($value) && $value !== '');

        foreach ($candidateIds as $candidateId) {
            if (ctype_digit($candidateId)) {
                $user = Users::query()->find((int) $candidateId);
                if ($user) return $user;
            }

            $subscription = Subscription::query()->where('revenuecat_user_id', $candidateId)->first();
            if ($subscription) {
                $user = Users::query()->find($subscription->user_id);
                if ($user) return $user;
            }
        }

        return null;
    }
}