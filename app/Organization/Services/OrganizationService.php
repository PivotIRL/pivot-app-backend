<?php

namespace App\Organization\Services;

use App\Models\User;
use App\Organization\Enums\AuditAction;
use App\Organization\Enums\AuditActorType;
use App\Organization\Enums\OrganizationStatus;
use App\Organization\Enums\Package;
use App\Organization\Exceptions\OrganizationActionException;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationContract;
use App\Organization\Repositories\Contracts\OrganizationRepositoryInterface;
use Illuminate\Support\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class OrganizationService
{
    public function __construct(
        protected OrganizationRepositoryInterface $organizations,
        protected AuditLogger $audit,
        protected ContractMembershipService $contractMemberships,
    ) {}

    public function create(array $data, User $actor): Organization
    {
        return DB::transaction(function () use ($data, $actor) {
            $entitlements = $data['entitlements'] ?? [];
            unset($data['entitlements']);

            $status = Carbon::parse($data['contract_start_at'])->isFuture()
                ? OrganizationStatus::PENDING
                : OrganizationStatus::ACTIVE;

            $organization = $this->organizations->create([
                ...$data,
                'status' => $status,
                'created_by' => $actor->id,
            ]);

            $this->createContractFromOrganization($organization);
            $this->syncEntitlements($organization, $entitlements);

            $this->audit->record(
                action: AuditAction::ORGANIZATION_CREATED,
                organization: $organization,
                target: $organization,
                metadata: [
                    'status' => $status->value,
                    'package_code' => $organization->package_code->value,
                    'seat_limit' => $organization->seat_limit,
                ],
                actor: $actor,
            );

            if ($organization->package_code === Package::CUSTOM) {
                $this->audit->record(
                    action: AuditAction::ORGANIZATION_ENTITLEMENTS_UPDATED,
                    organization: $organization,
                    target: $organization,
                    metadata: ['feature_keys' => array_column($entitlements, 'feature_key')],
                    actor: $actor,
                );
            }

            return $organization->load('currentContract');
        });
    }

    public function paginate(int $perPage = 25): LengthAwarePaginator
    {
        return $this->organizations->paginate($perPage);
    }

    /** @return Collection<int, OrganizationContract> */
    public function contractsFor(Organization $organization): Collection
    {
        return $this->organizations->contractsFor($organization);
    }

    public function update(Organization $organization, array $data, User $actor): Organization
    {
        if ($organization->status === OrganizationStatus::EXPIRED) {
            throw OrganizationActionException::invalidStatusTransition($organization->status, 'edited; use contract renewal');
        }

        return DB::transaction(function () use ($organization, $data, $actor) {
            $previousPackage = $organization->package_code;
            $previousStatus = $organization->status;
            $hasEntitlements = array_key_exists('entitlements', $data);
            $entitlements = $data['entitlements'] ?? [];
            unset($data['entitlements']);

            $contractFields = array_intersect_key($data, array_flip([
                'package_code',
                'seat_limit',
                'contract_start_at',
                'contract_end_at',
                'pricing_config',
            ]));
            $contractStatus = $organization->status;

            if ($contractFields !== []) {
                $contract = $organization->currentContract;
                $startsAt = $data['contract_start_at'] ?? $contract?->starts_at ?? $organization->contract_start_at;
                $contractStatus = $organization->status === OrganizationStatus::SUSPENDED
                    ? OrganizationStatus::SUSPENDED
                    : (Carbon::parse($startsAt)->isFuture() ? OrganizationStatus::PENDING : OrganizationStatus::ACTIVE);
                $data['status'] = $contractStatus;
            }

            $organization = $this->organizations->update($organization, $data);

            // Only the columns whose values really changed (Eloquent compares cast values).
            $changedFields = array_values(array_diff(array_keys($organization->getChanges()), ['updated_at']));

            if ($contractFields !== []) {
                if ($organization->currentContract) {
                    $organization->currentContract->update([
                        'package_code' => $organization->package_code,
                        'seat_limit' => $organization->seat_limit,
                        'starts_at' => $organization->contract_start_at,
                        'ends_at' => $organization->contract_end_at,
                        'pricing_config' => $organization->pricing_config,
                        'status' => $contractStatus,
                    ]);
                } elseif ($organization->contract_start_at && $organization->contract_end_at) {
                    $this->createContractFromOrganization($organization);
                }
            }

            if ($hasEntitlements || ($previousPackage === Package::CUSTOM && $organization->package_code !== Package::CUSTOM)) {
                $this->syncEntitlements($organization, $entitlements);
                $this->audit->record(
                    action: AuditAction::ORGANIZATION_ENTITLEMENTS_UPDATED,
                    organization: $organization,
                    target: $organization,
                    metadata: ['feature_keys' => array_column($entitlements, 'feature_key')],
                    actor: $actor,
                );
            }

            if ($changedFields !== []) {
                $metadata = ['changed_fields' => $changedFields];

                if (in_array('status', $changedFields, true)) {
                    $metadata['previous_status'] = $previousStatus->value;
                    $metadata['new_status'] = $organization->status->value;
                }

                $this->audit->record(
                    action: AuditAction::ORGANIZATION_UPDATED,
                    organization: $organization,
                    target: $organization,
                    metadata: $metadata,
                    actor: $actor,
                );
            }

            return $organization->load('currentContract');
        });
    }

    public function renew(
        Organization $organization,
        array $contractData,
        User $actor,
    ): Organization {
        return DB::transaction(function () use ($organization, $contractData, $actor) {
            $entitlements = $contractData['entitlements'] ?? [];
            unset($contractData['entitlements']);
            $current = $organization->currentContract;
            if (! $current && $organization->contract_start_at && $organization->contract_end_at) {
                $current = $this->createContractFromOrganization($organization);
            }
            if ($current) {
                $current->update(['status' => OrganizationStatus::EXPIRED]);
            }

            // A renewal never lifts a suspension; only OrganizationControlService::reactivate() does.
            $status = match (true) {
                $organization->status === OrganizationStatus::SUSPENDED => OrganizationStatus::SUSPENDED,
                Carbon::parse($contractData['contract_start_at'])->isFuture() => OrganizationStatus::PENDING,
                default => OrganizationStatus::ACTIVE,
            };

            $organization = $this->organizations->update($organization, [
                'package_code' => $contractData['package_code'],
                'seat_limit' => $contractData['seat_limit'],
                'contract_start_at' => $contractData['contract_start_at'],
                'contract_end_at' => $contractData['contract_end_at'],
                'pricing_config' => $contractData['pricing_config'] ?? null,
                'status' => $status,
            ]);

            $this->createContractFromOrganization($organization);
            $this->syncEntitlements($organization, $entitlements);

            // A future-start renewal restores members later, when the activation job runs.
            if ($status === OrganizationStatus::ACTIVE) {
                $this->contractMemberships->restoreAfterContractExpiry($organization, $actor);
            }

            $this->audit->record(
                action: AuditAction::ORGANIZATION_CONTRACT_RENEWED,
                organization: $organization,
                target: $organization->currentContract,
                metadata: [
                    'status' => $status->value,
                    'package_code' => $organization->package_code->value,
                    'seat_limit' => $organization->seat_limit,
                    'contract_end_at' => $organization->contract_end_at?->toISOString(),
                ],
                actor: $actor,
            );

            if ($organization->package_code === Package::CUSTOM) {
                $this->audit->record(
                    action: AuditAction::ORGANIZATION_ENTITLEMENTS_UPDATED,
                    organization: $organization,
                    target: $organization,
                    metadata: ['feature_keys' => array_column($entitlements, 'feature_key')],
                    actor: $actor,
                );
            }

            return $organization->load('currentContract');
        });
    }

    public function expirePastContracts(): int
    {
        $expiredCount = 0;
        $now = now();

        $this->organizations->eachExpiredBefore($now, function (Organization $organization) use (&$expiredCount): void {
            DB::transaction(function () use ($organization, &$expiredCount): void {
                $organization->refresh();
                if (! in_array($organization->status, [OrganizationStatus::PENDING, OrganizationStatus::ACTIVE, OrganizationStatus::SUSPENDED], true)) {
                    return;
                }

                $contract = $organization->currentContract;
                $contract?->update(['status' => OrganizationStatus::EXPIRED]);
                $organization->update(['status' => OrganizationStatus::EXPIRED]);

                $expiredMemberships = $this->contractMemberships->expireForContractEnd($organization);

                $this->audit->record(
                    action: AuditAction::ORGANIZATION_CONTRACT_EXPIRED,
                    organization: $organization,
                    target: $contract ?? $organization,
                    metadata: ['expired_memberships' => $expiredMemberships],
                    actorType: AuditActorType::SYSTEM,
                );

                $expiredCount++;
            });
        });

        return $expiredCount;
    }

    /**
     * Lifecycle step "activate → active": a pending organization becomes active once its contract starts.
     */
    public function activateStartedContracts(): int
    {
        $activatedCount = 0;
        $now = now();

        $this->organizations->eachPendingStartedBy($now, function (Organization $organization) use (&$activatedCount): void {
            DB::transaction(function () use ($organization, &$activatedCount): void {
                $organization->refresh();
                if ($organization->status !== OrganizationStatus::PENDING) {
                    return;
                }

                $contract = $organization->currentContract;
                $contract?->update(['status' => OrganizationStatus::ACTIVE]);
                $this->organizations->updateStatus($organization, OrganizationStatus::ACTIVE);

                $this->audit->record(
                    action: AuditAction::ORGANIZATION_CONTRACT_ACTIVATED,
                    organization: $organization,
                    target: $contract ?? $organization,
                    metadata: ['contract_start_at' => $organization->contract_start_at?->toISOString()],
                    actorType: AuditActorType::SYSTEM,
                );

                $this->contractMemberships->restoreAfterContractExpiry($organization);

                $activatedCount++;
            });
        });

        return $activatedCount;
    }

    private function createContractFromOrganization(Organization $organization): OrganizationContract
    {
        return $organization->contracts()->create([
            'status' => $organization->status,
            'package_code' => $organization->package_code,
            'seat_limit' => $organization->seat_limit,
            'starts_at' => $organization->contract_start_at,
            'ends_at' => $organization->contract_end_at,
            'pricing_config' => $organization->pricing_config,
        ]);
    }

    private function syncEntitlements(Organization $organization, array $entitlements): void
    {
        $organization->entitlements()->delete();

        if ($organization->package_code !== Package::CUSTOM) {
            return;
        }

        foreach ($entitlements as $entitlement) {
            $organization->entitlements()->create([
                'feature_key' => $entitlement['feature_key'],
                'value_json' => $entitlement['value_json'] ?? null,
            ]);
        }
    }
}
