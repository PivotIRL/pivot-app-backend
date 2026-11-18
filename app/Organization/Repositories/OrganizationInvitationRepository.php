<?php

namespace App\Organization\Repositories;

use App\Models\User;
use App\Organization\Enums\InvitationStatus;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationInvitation;
use App\Organization\Repositories\Contracts\OrganizationInvitationRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class OrganizationInvitationRepository implements OrganizationInvitationRepositoryInterface
{
    public function paginateFor(Organization $organization, ?InvitationStatus $status, int $perPage): LengthAwarePaginator
    {
        return $organization->invitations()
            ->when($status, fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->paginate($perPage);
    }

    public function create(Organization $organization, array $data): OrganizationInvitation
    {
        return $organization->invitations()->create($data);
    }

    public function findPending(string $organizationId, string $email): ?OrganizationInvitation
    {
        return OrganizationInvitation::query()
            ->where('organization_id', $organizationId)
            ->where('email', $email)
            ->where('status', InvitationStatus::PENDING)
            ->first();
    }

    public function findByTokenHashForUpdate(string $tokenHash): ?OrganizationInvitation
    {
        return OrganizationInvitation::query()
            ->where('token_hash', $tokenHash)
            ->lockForUpdate()
            ->first();
    }

    public function update(OrganizationInvitation $invitation, array $data): OrganizationInvitation
    {
        $invitation->update($data);

        return $invitation;
    }

    public function findUserByEmail(string $email): ?User
    {
        return User::query()->whereRaw('lower(email) = ?', [$email])->first();
    }
}
