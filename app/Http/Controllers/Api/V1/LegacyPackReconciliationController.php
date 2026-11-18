<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ChallengePacksWebhook;
use App\Models\User;
use App\Services\RevenueCatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LegacyPackReconciliationController extends Controller
{
    public function __invoke(Request $request, RevenueCatService $revenueCat)
    {
        $data = $request->validate([
            'packs' => 'required|array|min:1|max:100',
            'packs.*.transaction_id' => 'required|string|max:255|distinct',
            'packs.*.product_id' => 'required|string|max:255',
            'packs.*.total' => 'required|integer|min:0|max:100000',
            'packs.*.remaining' => 'required|integer|min:0|lte:packs.*.total',
        ]);
        $userId = $request->user()->id;
        $known = ChallengePacksWebhook::where('user_id', $userId)
            ->whereIn('transaction_id', array_column($data['packs'], 'transaction_id'))->pluck('transaction_id')->all();
        $environment = strtolower((string) config('membership.store_environment', 'production'));
        // Network verification must run outside balance locks. Never accept a customer ID from the device.
        $receipts = count($known) === count($data['packs']) ? ['ok' => true, 'items' => []]
            : $revenueCat->fetchCustomerPackPurchases((string) ($request->user()->revenuecat_user_id ?: $userId), $environment);
        return DB::transaction(function () use ($userId, $data, $receipts, $environment) {
            User::query()->lockForUpdate()->findOrFail($userId);
            $results = [];
            foreach ($data['packs'] as $local) {
                $pack = ChallengePacksWebhook::where('user_id', $userId)
                    ->where('transaction_id', $local['transaction_id'])->lockForUpdate()->first();
                $matched = $pack !== null; // Verified transaction ownership wins over stale local product metadata.
                $verifiedReceipt = collect($receipts['items'] ?? [])->contains(fn ($receipt) =>
                    ($receipt['store_purchase_identifier'] ?? null) === $local['transaction_id']
                    && ($receipt['environment'] ?? null) === $environment
                    && ($receipt['status'] ?? null) === 'owned'
                    && ($receipt['ownership'] ?? null) === 'purchased'
                    && in_array($receipt['store_product_id'] ?? null, ['1_challenge_pack', '3_challenge_pack'], true));
                // Old clients have no consumption IDs. Never infer another debit or mint credits
                // from a device's remaining count. Preserve the first snapshot for support/audit.
                DB::table('legacy_pack_reconciliations')->insertOrIgnore([
                    'user_id' => $userId, 'transaction_id' => $local['transaction_id'],
                    'product_id' => $local['product_id'], 'local_total' => $local['total'],
                    'local_remaining' => $local['remaining'],
                    'server_remaining' => $matched ? $pack->remaining : null,
                    'resolution' => $matched ? 'server_balance_preserved' : 'receipt_required',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($matched) {
                    DB::table('legacy_pack_reconciliations')->where('user_id', $userId)
                        ->where('transaction_id', $local['transaction_id'])
                        ->update(['resolution' => 'server_balance_preserved', 'updated_at' => now()]);
                }
                $results[] = ['transaction_id' => $local['transaction_id'],
                    'resolution' => $matched ? 'server_balance_preserved' : 'receipt_required',
                    'recovery_status' => $matched ? 'resolved' : (! $receipts['ok'] ? 'verification_unavailable'
                        : ($verifiedReceipt ? 'receipt_verified_balance_review_required' : 'receipt_not_found')),
                    // Read the current balance on every replay; never resurrect an old balance.
                    'pack' => $matched ? $pack : null];
            }
            return response()->json(['data' => $results]);
        });
    }
}
