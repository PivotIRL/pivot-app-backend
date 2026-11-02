<?php

namespace App\Organization\Repositories\Contracts;

use App\Models\User;
use App\Organization\Enums\InvitationStatus;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationInvitation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface OrganizationInvitationRepositoryInterface
{
    public function paginateFor(Organization $organization, ?InvitationStatus $status, int $perPage): LengthAwarePaginator;

    public function create(Organization $organization, array $data): OrganizationInvitation;

    public function findPending(string $organizationId, string $email): ?OrganizationInvitation;

    /**
     * Finds an invitation by token hash and locks its row until the transaction ends, so it is accepted once.
     */
    public function findByTokenHashForUpdate(string $tokenHash): ?OrganizationInvitation;

    public function update(OrganizationInvitation $invitation, array $data): OrganizationInvitation;

    /**
     * The Pivot account an invitation is addressed to, if one exists.
     */
    public function findUserByEmail(string $email): ?User;
}
