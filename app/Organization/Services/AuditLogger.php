<?php

namespace App\Organization\Services;

use App\Models\User;
use App\Organization\Enums\AuditAction;
use App\Organization\Enums\AuditActorType;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationAuditLog;
use App\Organization\Repositories\Contracts\OrganizationAuditLogRepositoryInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * The single entry point for writing organization audit history.
 * Every admin, SSO and SCIM change must go through record().
 */
class AuditLogger
{
    private const REDACTED = '[REDACTED]';

    private const SENSITIVE_KEYS = [
        'password',
        'token',
        'token_hash',
        'secret',
        'client_secret',
        'code',
        'code_hash',
    ];

    public function __construct(
        protected OrganizationAuditLogRepositoryInterface $auditLogs
    ) {}

    /**
     * @param  Organization|string|null  $organization  Null for platform-level events.
     * @param  User|null  $actor  Defaults to the authenticated user; null with no user means a system action.
     * @param  AuditActorType|null  $actorType  Set explicitly for SSO / SCIM actors.
     */
    public function record(
        AuditAction $action,
        Organization|string|null $organization = null,
        ?Model $target = null,
        array $metadata = [],
        ?User $actor = null,
        ?AuditActorType $actorType = null,
    ): OrganizationAuditLog {
        // An explicit SYSTEM action never borrows whoever happens to be logged in.
        $actor ??= $actorType === AuditActorType::SYSTEM ? null : $this->authenticatedUser();
        $request = request();

        return $this->auditLogs->create([
            'organization_id' => $organization instanceof Organization ? $organization->getKey() : $organization,
            'actor_user_id' => $actor?->getKey(),
            'actor_type' => $actorType ?? $this->resolveActorType($actor),
            'action' => $action,
            'target_type' => $target?->getMorphClass(),
            'target_id' => $target ? (string) $target->getKey() : null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'metadata' => $metadata === [] ? null : $this->redact($metadata),
        ]);
    }

    private function authenticatedUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    private function resolveActorType(?User $actor): AuditActorType
    {
        return match (true) {
            $actor === null => AuditActorType::SYSTEM,
            $actor->isPivotStaff() => AuditActorType::PIVOT_STAFF,
            default => AuditActorType::USER,
        };
    }

    private function redact(array $metadata): array
    {
        foreach ($metadata as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $metadata[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $metadata[$key] = $this->redact($value);
            }
        }

        return $metadata;
    }
}
