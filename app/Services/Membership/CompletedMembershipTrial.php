<?php

namespace App\Services\Membership;

use Illuminate\Support\Facades\DB;

class CompletedMembershipTrial
{
    public function find(int $userId, array $choice, array $transactionIds): ?object
    {
        $ids = array_values(array_unique(array_filter($transactionIds, fn ($id) => is_string($id) && $id !== '')));
        if ($ids === []) return null;
        $matches = [];
        foreach (DB::table('membership_attempts')->where('user_id', $userId)
            ->where('operation', 'trial')->where('status', 'completed')
            ->whereIn('verified_transaction_id', $ids)->get() as $attempt) {
            $quote = json_decode($attempt->quote, true) ?: [];
            if (($quote['product_id'] ?? null) === $choice['store_product_id']
                && ($quote['base_plan_id'] ?? null) === $choice['base_plan_id']) $matches[] = $attempt;
        }
        return count($matches) === 1 ? $matches[0] : null;
    }
}
