<?php

namespace App\Organization\Models;

use App\Organization\Database\Factories\OrganizationMembershipFactory;
use App\Organization\Enums\MembershipJoinMethod;
use App\Organization\Enums\MembershipStatus;
use App\Organization\Enums\OrganizationRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Soft-deleted rows are removed members: hidden from the dashboard and from route binding, but still found by
 * OrganizationMembershipRepository::findFor() so history, access checks and the rejoin block keep working.
 */
class OrganizationMembership extends Model
{
    use HasFactory, SoftDeletes;

    // Set when the organization's contract expires; only these memberships are restored on renewal.
    public const REASON_CONTRACT_EXPIRED = 'organization_contract_expired';

    // Set when the organization itself is removed.
    public const REASON_ORGANIZATION_REMOVED = 'organization_removed';

    // Set on an invited (not yet active) membership when its invitation is revoked.
    public const REASON_INVITATION_REVOKED = 'invitation_revoked';

    protected $fillable = [
        'organization_id',
        'user_id',
        'status',
        'joined_via',
        'joined_at',
        'activated_at',
        'deactivated_at',
        'expires_at',
        'deactivation_reason',
        'provisioning_source_id',
        // Cleared when a removed member is invited or restored again.
        'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'joined_via' => MembershipJoinMethod::class,
            'joined_at' => 'datetime',
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function newFactory(): OrganizationMembershipFactory
    {
        return OrganizationMembershipFactory::new();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Attach the member's active role assignments (not persisted). ORG_MEMBER is always included, because
     * membership itself is the member role; the list is ordered highest rank first.
     *
     * @param  iterable<OrganizationRole>  $assignedRoles
     */
    public function setActiveRoles(iterable $assignedRoles): static
    {
        $roles = collect($assignedRoles)
            ->push(OrganizationRole::ORG_MEMBER)
            ->unique(fn (OrganizationRole $role) => $role->value)
            ->sortByDesc(fn (OrganizationRole $role) => $role->rank())
            ->values();

        return $this->setRelation('activeRoles', $roles);
    }
}
