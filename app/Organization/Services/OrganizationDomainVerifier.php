<?php

namespace App\Organization\Services;

use App\Organization\Models\OrganizationDomain;

class OrganizationDomainVerifier
{
    public function hasVerificationRecord(OrganizationDomain $domain): bool
    {
        $records = dns_get_record('_pivot-verification.'.$domain->domain, DNS_TXT);

        if ($records === false) {
            return false;
        }

        foreach ($records as $record) {
            if (($record['txt'] ?? null) === $domain->verification_token) {
                return true;
            }
        }

        return false;
    }
}
