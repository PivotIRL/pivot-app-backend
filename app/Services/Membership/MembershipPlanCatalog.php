<?php

namespace App\Services\Membership;

use Illuminate\Support\Facades\DB;

class MembershipPlanCatalog
{
    public function plans(): array
    {
        return DB::table('membership_plans')->orderBy('key')->get()->mapWithKeys(fn ($row) => [
            $row->key => ['revision' => (int) $row->revision,
                'limits' => json_decode($row->limits, true, flags: JSON_THROW_ON_ERROR)],
        ])->all();
    }

    public function limits(string $key): array
    {
        return $this->plans()[$key]['limits'] ?? throw new \RuntimeException('Membership plan is not configured.');
    }

    public function response(): array
    {
        return ['schema_version' => 1, 'plans' => $this->plans()];
    }
}
