<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\NormalizesOrganizationFormInput;
use App\Organization\Http\Requests\UpdateOrganizationRequest;
use App\Organization\Models\Organization;
use Illuminate\Support\Facades\Gate;

/**
 * Admin Dashboard form for editing an organization. Uses the same rules as the Organization API.
 */
class UpdateAdminOrganizationRequest extends UpdateOrganizationRequest
{
    use NormalizesOrganizationFormInput;

    public function authorize(): bool
    {
        return Gate::allows('manage-organizations');
    }

    protected function existingVolumeBands(): ?array
    {
        $organization = $this->route('organization');

        return $organization instanceof Organization
            ? ($organization->pricing_config['volume_bands'] ?? null)
            : null;
    }
}
