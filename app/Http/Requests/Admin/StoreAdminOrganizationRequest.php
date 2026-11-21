<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\NormalizesOrganizationFormInput;
use App\Organization\Http\Requests\StoreOrganizationRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Admin Dashboard form for creating an organization. Uses the same rules as the Organization API.
 */
class StoreAdminOrganizationRequest extends StoreOrganizationRequest
{
    use NormalizesOrganizationFormInput;

    public function authorize(): bool
    {
        return Gate::allows('manage-organizations');
    }
}
