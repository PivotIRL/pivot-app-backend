<?php

namespace App\Organization\Data;

use Illuminate\Support\Carbon;

/**
 * A successful Organization Dashboard login: the new token and the user's current access.
 */
final class OrganizationDashboardLoginResult
{
    public function __construct(
        public readonly string $plainTextToken,
        public readonly Carbon $expiresAt,
        public readonly CorporateAccessSummary $summary,
    ) {}
}
