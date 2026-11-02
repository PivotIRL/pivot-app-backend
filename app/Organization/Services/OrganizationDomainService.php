<?php

namespace App\Organization\Services;

use App\Models\User;
use App\Organization\Enums\AuditAction;
use App\Organization\Enums\OrganizationDomainStatus;
use App\Organization\Exceptions\OrganizationActionException;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationDomain;
use App\Organization\Repositories\Contracts\OrganizationDomainRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrganizationDomainService
{
    public function __construct(
        protected OrganizationDomainRepositoryInterface $domains,
        protected AuditLogger $audit,
        protected OrganizationDomainVerifier $verifier,
    ) {}

    /** @return Collection<int, OrganizationDomain> */
    public function list(Organization $organization): Collection
    {
        return $this->domains->listFor($organization);
    }

    public function add(Organization $organization, array $data, User $actor): OrganizationDomain
    {
        $data['domain'] = strtolower(trim($data['domain']));

        return DB::transaction(function () use ($organization, $data, $actor) {
            $domain = $this->domains->create($organization, [
                ...$data,
                'status' => OrganizationDomainStatus::PENDING->value,
                'verified_at' => null,
                'verification_token' => 'pivot-domain-verification='.Str::random(48),
            ]);

            $this->audit->record(
                action: AuditAction::ORGANIZATION_DOMAIN_ADDED,
                organization: $organization,
                target: $domain,
                metadata: [
                    'domain' => $domain->domain,
                    'include_subdomains' => $domain->include_subdomains,
                ],
                actor: $actor,
            );

            return $domain;
        });
    }

    public function verify(OrganizationDomain $domain, User $actor): OrganizationDomain
    {
        if ($domain->status === OrganizationDomainStatus::VERIFIED) {
            throw OrganizationActionException::domainAlreadyVerified();
        }

        if ($this->domains->isVerifiedByAnotherOrganization($domain)) {
            throw OrganizationActionException::domainVerifiedByAnotherOrganization();
        }

        if (! $this->verifier->hasVerificationRecord($domain)) {
            throw OrganizationActionException::domainVerificationFailed();
        }

        return DB::transaction(function () use ($domain, $actor) {
            try {
                $domain->update([
                    'status' => OrganizationDomainStatus::VERIFIED->value,
                    'verification_method' => 'dns_txt',
                    'verified_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Another organization verified the same domain at the same moment.
                throw OrganizationActionException::domainVerifiedByAnotherOrganization();
            }

            $this->audit->record(
                action: AuditAction::ORGANIZATION_DOMAIN_VERIFIED,
                organization: $domain->organization,
                target: $domain,
                metadata: ['domain' => $domain->domain, 'verification_method' => 'dns_txt'],
                actor: $actor,
            );

            $this->removeCompetingClaims($domain, $actor);

            return $domain->refresh();
        });
    }

    public function makePrimary(OrganizationDomain $domain, User $actor): OrganizationDomain
    {
        if ($domain->status !== OrganizationDomainStatus::VERIFIED) {
            throw OrganizationActionException::domainMustBeVerified();
        }

        return DB::transaction(function () use ($domain, $actor) {
            $domain = $this->domains->makePrimary($domain);

            $this->audit->record(
                action: AuditAction::ORGANIZATION_DOMAIN_SET_PRIMARY,
                organization: $domain->organization,
                target: $domain,
                metadata: ['domain' => $domain->domain],
                actor: $actor,
            );

            return $domain;
        });
    }

    // The verified owner wins: other organizations' pending claims for this domain are removed.
    private function removeCompetingClaims(OrganizationDomain $verified, User $actor): void
    {
        foreach ($this->domains->competingClaims($verified) as $claim) {
            $this->domains->delete($claim);

            $this->audit->record(
                action: AuditAction::ORGANIZATION_DOMAIN_REMOVED,
                organization: $claim->organization_id,
                target: $claim,
                metadata: ['domain' => $claim->domain, 'reason' => 'verified_by_another_organization'],
                actor: $actor,
            );
        }
    }

    public function remove(OrganizationDomain $domain, User $actor): void
    {
        DB::transaction(function () use ($domain, $actor) {
            $organization = $domain->organization;
            $domainName = $domain->domain;
            $this->domains->delete($domain);

            $this->audit->record(
                action: AuditAction::ORGANIZATION_DOMAIN_REMOVED,
                organization: $organization,
                target: $domain,
                metadata: ['domain' => $domainName],
                actor: $actor,
            );
        });
    }
}
