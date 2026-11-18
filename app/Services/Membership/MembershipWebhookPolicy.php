<?php

namespace App\Services\Membership;

use Illuminate\Support\Carbon;

class MembershipWebhookPolicy
{
    public const EVENTS = [
        'INITIAL_PURCHASE', 'RENEWAL', 'CANCELLATION', 'EXPIRATION', 'PRODUCT_CHANGE',
        'BILLING_ISSUE', 'NON_RENEWING_PURCHASE', 'REFUND', 'TRANSFER', 'TEST',
        'UNCANCELLATION', 'SUBSCRIPTION_EXTENDED', 'SUBSCRIPTION_PAUSED', 'REFUND_REVERSED',
    ];

    public static function revoked(?string $type, string $path, ?Carbon $expiresAt): bool
    {
        // Non-renewing purchases cannot cancel renewal: their cancellation is a refund.
        return $type === 'REFUND'
            || ($type === 'CANCELLATION' && $path === 'lifetime')
            || (in_array($type, ['CANCELLATION', 'EXPIRATION'], true)
                && $expiresAt !== null && ! $expiresAt->isFuture())
            || ($type === 'EXPIRATION' && $expiresAt === null);
    }

    public static function renewalStatus(array $payload): ?string
    {
        return match ($payload['type'] ?? null) {
            'CANCELLATION', 'SUBSCRIPTION_PAUSED' => 'cancelled',
            'UNCANCELLATION' => 'renewing',
            default => isset($payload['auto_renew']) ? ($payload['auto_renew'] ? 'renewing' : 'cancelled') : null,
        };
    }
}
