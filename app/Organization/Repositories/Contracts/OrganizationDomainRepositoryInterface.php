<?php

namespace App\Organization\Repositories\Contracts;

use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationDomain;
use Illuminate\Database\Eloquent\Collection;

interface OrganizationDomainRepositoryInterface
{
    /** @return Collection<int, OrganizationDomain> */
    public function listFor(Organization $organization): Collection;

    public function create(Organization $organization, array $data): OrganizationDomain;

    public function makePrimary(OrganizationDomain $domain): OrganizationDomain;

    public function isVerifiedByAnotherOrganization(OrganizationDomain $domain): bool;

    /** @return Collection<int, OrganizationDomain> Pending claims for the same domain by other organizations. */
    public function competingClaims(OrganizationDomain $domain): Collection;

    public function delete(OrganizationDomain $domain): void;

    /**
     * The verified domain that covers an email domain: an exact match, or a parent domain
     * with include_subdomains. The most specific match wins.
     */
    public function findVerifiedForEmailDomain(string $emailDomain): ?OrganizationDomain;
}
