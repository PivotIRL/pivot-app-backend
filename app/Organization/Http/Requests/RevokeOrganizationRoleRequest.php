<?php

namespace App\Organization\Http\Requests;

use App\Organization\Http\Requests\Concerns\HasOrganizationValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

class RevokeOrganizationRoleRequest extends FormRequest
{
    use HasOrganizationValidationMessages;

    // Authorization is handled by OrganizationRoleAssignmentPolicy in the controller.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
