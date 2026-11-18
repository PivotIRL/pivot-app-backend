<?php

namespace App\Organization\Services;

use App\Models\User;
use App\Organization\Enums\AuditAction;
use App\Organization\Exceptions\OrganizationActionException;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationRedemptionCode;
use App\Organization\Repositories\Contracts\OrganizationRedemptionCodeRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RedemptionCodeService
{
    public function __construct(
        protected OrganizationRedemptionCodeRepositoryInterface $codes,
        protected AuditLogger $audit,
    ) {}

    public function list(Organization $organization, int $perPage = 25): LengthAwarePaginator
    {
        return $this->codes->paginateFor($organization, $perPage);
    }

    /** @return array{code: OrganizationRedemptionCode, plaintext: string} */
    public function create(Organization $organization, array $data, User $actor): array
    {
        return DB::transaction(function () use ($organization, $data, $actor) {
            $plaintext = 'PIVOT-'.Str::upper(Str::random(32));
            $prefix = substr($plaintext, 0, 16);
            $code = $this->codes->create($organization, [
                ...$data,
                'code_hash' => hash('sha256', $plaintext),
                'code_prefix' => $prefix,
                'created_by' => $actor->id,
                'uses_count' => 0,
            ]);

            $this->audit->record(
                action: AuditAction::ORGANIZATION_CODE_CREATED,
                organization: $organization,
                target: $code,
                metadata: [
                    'name' => $code->name,
                    'code_type' => $code->code_type->value,
                    'code_prefix' => $prefix,
                    'max_uses' => $code->max_uses,
                    'expires_at' => $code->expires_at?->toISOString(),
                ],
                actor: $actor,
            );

            return ['code' => $code, 'plaintext' => $plaintext];
        });
    }

    public function deactivate(OrganizationRedemptionCode $code, User $actor): OrganizationRedemptionCode
    {
        if (! $code->is_active) {
            throw OrganizationActionException::codeAlreadyInactive();
        }

        return DB::transaction(function () use ($code, $actor) {
            $this->codes->deactivate($code);

            $this->audit->record(
                action: AuditAction::ORGANIZATION_CODE_DEACTIVATED,
                organization: $code->organization,
                target: $code,
                metadata: ['code_prefix' => $code->code_prefix],
                actor: $actor,
            );

            return $code;
        });
    }
}
