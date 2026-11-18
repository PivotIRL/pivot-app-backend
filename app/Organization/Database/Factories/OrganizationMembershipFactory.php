<?php

namespace App\Organization\Database\Factories;

use App\Organization\Enums\MembershipStatus;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationMembership>
 */
class OrganizationMembershipFactory extends Factory
{
    protected $model = OrganizationMembership::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => fn () => User::create([
                'name' => fake()->name(),
                'email' => fake()->unique()->safeEmail(),
                'password' => bcrypt('password'),
            ])->id,
            'status' => MembershipStatus::ACTIVE,
            'joined_via' => 'work_email',
            'joined_at' => now(),
            'activated_at' => now(),
        ];
    }

    public function deactivated(): static
    {
        return $this->state([
            'status' => MembershipStatus::DEACTIVATED,
            'deactivated_at' => now(),
            'deactivation_reason' => 'offboarded',
        ]);
    }
}
