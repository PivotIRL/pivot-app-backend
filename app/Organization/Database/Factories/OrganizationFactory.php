<?php

namespace App\Organization\Database\Factories;

use App\Organization\Enums\OrganizationStatus;
use App\Organization\Enums\OrganizationType;
use App\Organization\Enums\Package;
use App\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'organization_type' => OrganizationType::CORPORATE,
            'status' => OrganizationStatus::ACTIVE,
            'package_code' => Package::STANDARD,
            'seat_limit' => 50,
            'contract_start_at' => now(),
            'contract_end_at' => now()->addYear(),
            'timezone' => 'UTC',
            'country_code' => 'GB',
        ];
    }

    public function premium(): static
    {
        return $this->state(['package_code' => Package::PREMIUM]);
    }

    public function custom(): static
    {
        return $this->state(['package_code' => Package::CUSTOM]);
    }

    public function suspended(): static
    {
        return $this->state(['status' => OrganizationStatus::SUSPENDED]);
    }

    public function expired(): static
    {
        return $this->state([
            'status' => OrganizationStatus::EXPIRED,
            'contract_end_at' => now()->subDay(),
        ]);
    }
}
