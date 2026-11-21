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
            $word = $this->codeWord($organization);
            do {
                $plaintext = $word.'-'.$this->randomSuffix();
                $codeHash = hash('sha256', Str::upper($plaintext));
            } while (OrganizationRedemptionCode::query()->where('code_hash', $codeHash)->exists());

            // Only the non-secret organization word is stored as the visible prefix.
            $prefix = $word.'-';
            $code = $this->codes->create($organization, [
                ...$data,
                'code_hash' => $codeHash,
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

    /** First word of the organization name, lowercased and ASCII-only, e.g. "Test Organization" => "test". */
    protected function codeWord(Organization $organization): string
    {
        $word = Str::before(Str::slug((string) $organization->name), '-');
        $word = substr(preg_replace('/[^a-z0-9]/', '', $word), 0, 15);

        return $word !== '' ? $word : 'org';
    }

    protected function randomSuffix(int $length = 6): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $suffix = '';
        for ($i = 0; $i < $length; $i++) {
            $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $suffix;
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
