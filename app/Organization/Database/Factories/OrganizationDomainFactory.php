<?php

namespace App\Organization\Database\Factories;

use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationDomain>
 */
class OrganizationDomainFactory extends Factory
{
    protected $model = OrganizationDomain::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'domain' => fake()->unique()->domainName(),
            'status' => 'verified',
            'is_primary' => false,
            'verification_method' => 'manual',
            'include_subdomains' => false,
            'verified_at' => now(),
        ];
    }

    public function primary(): static
    {
        return $this->state(['is_primary' => true]);
    }
}
