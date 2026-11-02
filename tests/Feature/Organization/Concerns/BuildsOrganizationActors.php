<?php

namespace Tests\Feature\Organization\Concerns;

use App\Models\User;
use App\Organization\Enums\OrganizationRole;
use App\Organization\Enums\PivotRole;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationMembership;
use App\Organization\Models\OrganizationRoleAssignment;

trait BuildsOrganizationActors
{
    /**
     * The stock UserFactory sets `email_verified_at`, a column the users
     * migration in this repo never added, so build users directly instead.
     */
    protected function makeUser(): User
    {
        return User::create([
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
        ]);
    }

    protected function makeStaff(PivotRole $role): User
    {
        $user = $this->makeUser();
        $user->forceFill(['pivot_role' => $role])->save();

        return $user;
    }

    protected function makeMember(Organization $organization, ?User $user = null): User
    {
        $user ??= $this->makeUser();

        OrganizationMembership::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
        ]);

        return $user;
    }

    protected function makeOrgUser(Organization $organization, OrganizationRole $role): User
    {
        $user = $this->makeMember($organization);
        $this->grantRole($user, $organization, $role);

        return $user;
    }

    protected function grantRole(User $user, Organization $organization, OrganizationRole $role): OrganizationRoleAssignment
    {
        return OrganizationRoleAssignment::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);
    }
}
