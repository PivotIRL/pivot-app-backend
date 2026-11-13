<?php
namespace App\Repositories;

use App\Models\ChallengePacksWebhook;
use App\Repositories\Contracts\ChallengePackRepositoryInterface;
use Illuminate\Support\Facades\DB;

class ChallengePackRepository implements ChallengePackRepositoryInterface
{
    public function getByUserId(int $userId)
    {
        return ChallengePacksWebhook::where('user_id', $userId)->get();
    }


    public function getUnusedByUserId(int $userId)
    {
        return ChallengePacksWebhook::where('user_id', $userId)
            ->where('status', '!=', 'used') 
            ->get();
    }

    public function getChallengePackDetails(int $userId, string $transactionId)
    {
        return ChallengePacksWebhook::where('user_id', $userId)
            ->where('transaction_id', $transactionId)
            ->where('status', '!=', 'used') 
            ->first();
    }

    public function decrementRemaining(int $userId, string $transactionId, int $usageCount)
    {
        if ($usageCount < 1) return null;

        return DB::transaction(function () use ($userId, $transactionId, $usageCount) {
            \App\Models\User::query()->lockForUpdate()->findOrFail($userId);
            $pack = ChallengePacksWebhook::where('user_id', $userId)
                ->where('transaction_id', $transactionId)
                ->lockForUpdate()
                ->first();

            if (! $pack) return null;
            $reserved = DB::table('challenge_quota_reservations')
                ->where('user_id', $userId)
                ->where('pack_transaction_id', $transactionId)
                ->where('status', 'reserved')
                ->where(fn ($q) => $q->where('durable_hold', true)->orWhere('expires_at', '>', now()))
                ->count();
            if ($pack->remaining - $reserved < $usageCount) return null;

            $remaining = $pack->remaining - $usageCount;
            $pack->update([
                'remaining' => $remaining,
                'status' => $remaining === 0 ? 'used' : $pack->status,
            ]);

            return $pack->fresh();
        });
    }
}
