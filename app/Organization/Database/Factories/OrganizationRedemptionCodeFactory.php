<?php

namespace App\Organization\Database\Factories;

use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationRedemptionCode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OrganizationRedemptionCode>
 */
class OrganizationRedemptionCodeFactory extends Factory
{
    protected $model = OrganizationRedemptionCode::class;

    public function definition(): array
    {
        $code = Str::upper(Str::random(10));

        return [
            'organization_id' => Organization::factory(),
            'name' => 'Company code',
            'code_hash' => hash('sha256', $code),
            'code_prefix' => substr($code, 0, 4),
            'max_uses' => 100,
            'uses_count' => 0,
            'starts_at' => now(),
            'expires_at' => now()->addMonths(3),
            'is_active' => true,
        ];
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }
}
