<?php

namespace App\Organization\Repositories;

use App\Organization\Enums\OrganizationDomainStatus;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationDomain;
use App\Organization\Repositories\Contracts\OrganizationDomainRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class OrganizationDomainRepository implements OrganizationDomainRepositoryInterface
{
    public function listFor(Organization $organization): Collection
    {
        return $organization->domains()->orderByDesc('is_primary')->orderBy('domain')->get();
    }

    public function create(Organization $organization, array $data): OrganizationDomain
    {
        return $organization->domains()->create($data);
    }

    public function makePrimary(OrganizationDomain $domain): OrganizationDomain
    {
        return DB::transaction(function () use ($domain) {
            $domain->organization->domains()->where('id', '!=', $domain->getKey())->update(['is_primary' => false]);
            $domain->update(['is_primary' => true]);

            return $domain->refresh();
        });
    }

    public function isVerifiedByAnotherOrganization(OrganizationDomain $domain): bool
    {
        return OrganizationDomain::query()
            ->where('domain', $domain->domain)
            ->where('organization_id', '!=', $domain->organization_id)
            ->where('status', OrganizationDomainStatus::VERIFIED)
            ->exists();
    }

    public function competingClaims(OrganizationDomain $domain): Collection
    {
        return OrganizationDomain::query()
            ->where('domain', $domain->domain)
            ->where('organization_id', '!=', $domain->organization_id)
            ->where('status', OrganizationDomainStatus::PENDING)
            ->get();
    }

    public function delete(OrganizationDomain $domain): void
    {
        $domain->delete();
    }

    public function findVerifiedForEmailDomain(string $emailDomain): ?OrganizationDomain
    {
        // eng.acme.com → parents [acme.com, com]
        $labels = explode('.', $emailDomain);
        $parents = [];
        for ($i = 1; $i < count($labels); $i++) {
            $parents[] = implode('.', array_slice($labels, $i));
        }

        return OrganizationDomain::query()
            ->with('organization')
            ->where('status', OrganizationDomainStatus::VERIFIED)
            ->where(fn ($query) => $query
                ->where('domain', $emailDomain)
                ->orWhere(fn ($query) => $query->whereIn('domain', $parents)->where('include_subdomains', true)))
            ->orderByRaw('length(domain) desc')
            ->first();
    }
}
