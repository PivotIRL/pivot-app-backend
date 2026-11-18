<?php

namespace App\Services\Membership;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VerifiedMembershipProductResolver
{
    public function __construct(private readonly MembershipProductCatalog $catalog) {}

    public function resolve(
        int $userId,
        array $identifiers,
        ?string $packageHint,
        ?string $basePlanHint,
        string $environment,
        mixed $transactionId,
        array $operations = ['trial', 'purchase', 'change'],
    ): ?array {
        $explicit = $this->catalog->resolve($identifiers, $basePlanHint, $packageHint);
        if ($explicit) return $explicit;
        if ($basePlanHint !== null && $basePlanHint !== '') return null;

        $productIdentifiers = array_values(array_unique(array_filter(array_map(
            static fn ($id) => is_string($id) ? strtolower(trim(explode(':', $id, 2)[0])) : null,
            $identifiers
        ))));
        $evidence = [];

        $transactionIds = array_values(array_unique(array_filter(
            is_array($transactionId) ? $transactionId : [$transactionId],
            static fn ($id) => is_string($id) && $id !== '',
        )));
        if ($transactionIds !== []) {
            $purchaseQuery = DB::table('verified_purchases')
                ->where('user_id', $userId)
                ->where('environment', strtolower($environment))
                ->where(function ($query) use ($transactionIds) {
                    $query->whereIn('transaction_id', $transactionIds)
                        ->orWhereIn('original_transaction_id', $transactionIds);
                });
            foreach ($purchaseQuery->get(['product_id', 'base_plan_id', 'package']) as $purchase) {
                if ($productIdentifiers && ! in_array(strtolower($purchase->product_id), $productIdentifiers, true)) continue;
                $choice = $this->catalog->resolve([$purchase->product_id], $purchase->base_plan_id, $purchase->package);
                if ($choice) $evidence[$choice['product_id'] . '|' . $choice['base_plan_id']] = $choice;
            }
        }

        foreach (DB::table('membership_attempts')
            ->where('user_id', $userId)
            ->whereIn('operation', $operations)
            ->whereIn('status', ['pending', 'scheduled', 'cancelled'])
            ->latest('created_at')
            ->get(['quote']) as $attempt) {
            $quote = json_decode($attempt->quote, true);
            if (! is_array($quote)) continue;
            $quotedProduct = strtolower((string) ($quote['product_id'] ?? ''));
            $quotedStoreProduct = explode(':', $quotedProduct, 2)[0];
            if ($quotedProduct === '' || ($productIdentifiers && ! in_array($quotedStoreProduct, $productIdentifiers, true))) continue;
            $choice = $this->catalog->resolve([$quotedProduct], $quote['base_plan_id'] ?? null, $quote['selected_package'] ?? $packageHint);
            if ($choice) $evidence[$choice['product_id'] . '|' . $choice['base_plan_id']] = $choice;
        }

        return count($evidence) === 1 ? array_values($evidence)[0] : null;
    }

    public function logUnresolved(array $context): void
    {
        Log::warning('RevenueCat membership product could not be mapped unambiguously.', $context);
    }
}
