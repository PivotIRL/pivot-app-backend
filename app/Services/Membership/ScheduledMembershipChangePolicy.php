<?php

namespace App\Services\Membership;

class ScheduledMembershipChangePolicy
{
    public function __construct(private readonly MembershipProductCatalog $catalog) {}

    public function canCancel(array $quote, array $subscriptions, string $environment, int $nowMs): bool
    {
        if (($quote['replacement_mode'] ?? null) !== 'DEFERRED') return false;
        $source = $this->catalog->resolve([$quote['old_product_id'] ?? ''], null, null);
        $target = $this->catalog->resolve([$quote['product_id'] ?? ''], $quote['base_plan_id'] ?? null, null);
        if (! $source || ! $target) return false;
        $cancelledSource = false;
        foreach ($subscriptions as $item) {
            if (($item['environment'] ?? null) !== $environment) continue;
            $choice = $this->catalog->resolve([$item['store_product_id'] ?? $item['product_id'] ?? ''], $item['base_plan_id'] ?? null, null);
            // Unknown identity or a still scheduled change is not cancellation evidence.
            if (! $choice || ! array_key_exists('pending_changes', $item) || $item['pending_changes'] !== null) return false;
            if ($choice['product_id'] === $target['product_id']) return false;
            if ($choice['product_id'] !== $source['product_id']) continue;
            $ends = $item['ends_at'] ?? $item['current_period_ends_at'] ?? null;
            if (($item['gives_access'] ?? null) !== false || ($item['status'] ?? null) !== 'expired'
                || ($item['auto_renewal_status'] ?? null) !== 'will_not_renew'
                || ! is_numeric($ends) || $ends > $nowMs) return false;
            $cancelledSource = true;
        }
        // Empty state and quote timeout alone must never settle a scheduled payment.
        return $cancelledSource;
    }
}
