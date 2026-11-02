<?php

namespace App\Organization\Database\Seeders;

use App\Organization\Enums\OrganizationDomainStatus;
use App\Organization\Enums\OrganizationStatus;
use App\Organization\Enums\OrganizationType;
use App\Organization\Enums\Package;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationDomain;
use Illuminate\Database\Seeder;

class PivotTestOrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::updateOrCreate(
            ['slug' => 'pivot-test-organization'],
            [
                'name' => 'Pivot Test Organization',
                'organization_type' => OrganizationType::CORPORATE,
                'account_type' => 'internal',
                'status' => OrganizationStatus::ACTIVE,
                'package_code' => Package::PREMIUM,
                'seat_limit' => 50,
                'contract_start_at' => now()->startOfDay(),
                'contract_end_at' => now()->addYear()->endOfDay(),
                'timezone' => 'UTC',
                'reporting_config' => ['privacy_threshold' => 20],
            ]
        );

        // Contract history record (B3). Only created once, so reseeding does not add duplicates.
        if (! $organization->contracts()->exists()) {
            $organization->contracts()->create([
                'status' => $organization->status,
                'package_code' => $organization->package_code,
                'seat_limit' => $organization->seat_limit,
                'starts_at' => $organization->contract_start_at,
                'ends_at' => $organization->contract_end_at,
                'pricing_config' => $organization->pricing_config,
            ]);
        }

        OrganizationDomain::updateOrCreate(
            ['organization_id' => $organization->id, 'domain' => 'pivot-test.internal'],
            [
                'status' => OrganizationDomainStatus::VERIFIED,
                'is_primary' => true,
                'verification_method' => 'manual',
                'include_subdomains' => false,
                'verified_at' => now(),
            ]
        );
    }
}
