<?php

namespace App\Services\Membership;

use App\Models\User;

/** Server-owned routing: clients and webhook payloads cannot opt an account into sandbox. */
class MembershipEnvironment
{
    public function forUser(User $user): string
    {
        $default = strtolower(trim((string) config('membership.store_environment', 'production')));
        if (! in_array($default, ['production', 'sandbox'], true)) {
            throw new \RuntimeException('Invalid membership store environment configuration.');
        }
        if ($default === 'sandbox') return 'sandbox';
        $testers = array_filter(array_map('trim', explode(',', (string) config('membership.sandbox_user_ids', ''))));
        return in_array((string) $user->id, $testers, true) ? 'sandbox' : 'production';
    }

    public function accepts(User $user, ?string $environment): bool
    {
        return strtolower($environment ?? 'production') === $this->forUser($user);
    }
}
