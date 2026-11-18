<?php

namespace App\Organization\Database\Factories;

use App\Organization\Enums\OrganizationRole;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationRoleAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationRoleAssignment>
 */
class OrganizationRoleAssignmentFactory extends Factory
{
    protected $model = OrganizationRoleAssignment::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => fn () => User::create([
                'name' => fake()->name(),
                'email' => fake()->unique()->safeEmail(),
                'password' => bcrypt('password'),
            ])->id,
            'role' => OrganizationRole::ORG_ADMIN,
            'assigned_at' => now(),
        ];
    }
}
