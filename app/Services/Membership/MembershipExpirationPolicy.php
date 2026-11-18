<?php

namespace App\Services\Membership;

use Illuminate\Support\Carbon;

class MembershipExpirationPolicy
{
    public function apply(
        string $status,
        ?string $paymentPath,
        ?string $effectivePackage,
        mixed $trialEndsAt,
        mixed $accessEndsAt,
        Carbon $now,
    ): array
    {
        $effectiveStatus = $status;
        $expired = false;

        if ($status === 'trial') {
            $deadline = $trialEndsAt ?: $accessEndsAt;
            $expired = ! $effectivePackage || ! $deadline || ! Carbon::parse($deadline)->greaterThan($now);
        } elseif (in_array($status, ['active', 'billing_restricted'], true)) {
            $expired = ! $effectivePackage
                || ($accessEndsAt && ! Carbon::parse($accessEndsAt)->greaterThan($now))
                || (! $accessEndsAt && $paymentPath !== 'lifetime');
        }

        if ($expired) $effectiveStatus = 'expired';

        return ['status' => $effectiveStatus, 'expired' => $expired];
    }
}
