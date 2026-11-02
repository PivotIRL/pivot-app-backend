<?php

namespace App\Organization\Services;

use App\Models\User;
use App\Organization\Enums\AuditAction;
use App\Organization\Enums\PivotRole;
use Illuminate\Support\Facades\DB;

/**
 * Grants and revokes global Pivot staff roles. Not exposed over HTTP; used by the pivot:role command.
 */
class PivotRoleService
{
    public function __construct(
        protected AuditLogger $audit
    ) {}

    public function grant(User $user, PivotRole $role, ?User $actor = null): User
    {
        $previous = $user->pivot_role;

        return DB::transaction(function () use ($user, $role, $actor, $previous) {
            $user->forceFill(['pivot_role' => $role])->save();

            $this->audit->record(
                action: AuditAction::PIVOT_ROLE_GRANTED,
                target: $user,
                metadata: ['role' => $role->value, 'previous_role' => $previous?->value],
                actor: $actor,
            );

            return $user;
        });
    }

    public function revoke(User $user, ?User $actor = null): User
    {
        $previous = $user->pivot_role;

        if ($previous === null) {
            return $user;
        }

        return DB::transaction(function () use ($user, $actor, $previous) {
            $user->forceFill(['pivot_role' => null])->save();

            $this->audit->record(
                action: AuditAction::PIVOT_ROLE_REVOKED,
                target: $user,
                metadata: ['previous_role' => $previous->value],
                actor: $actor,
            );

            return $user;
        });
    }
}
