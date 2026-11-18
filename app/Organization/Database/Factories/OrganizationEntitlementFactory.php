<?php

namespace App\Organization\Database\Factories;

use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationEntitlement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationEntitlement>
 */
class OrganizationEntitlementFactory extends Factory
{
    protected $model = OrganizationEntitlement::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory()->custom(),
            'feature_key' => fake()->unique()->slug(2),
            'value_json' => ['enabled' => true],
        ];
    }
}
