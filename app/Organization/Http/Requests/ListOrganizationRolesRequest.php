<?php

namespace App\Organization\Http\Requests;

use App\Organization\Enums\OrganizationRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListOrganizationRolesRequest extends FormRequest
{
    // Authorization is handled by OrganizationRoleAssignmentPolicy in the controller.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role' => ['nullable', Rule::enum(OrganizationRole::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function role(): ?OrganizationRole
    {
        return $this->enum('role', OrganizationRole::class);
    }

    public function perPage(): int
    {
        return $this->integer('per_page', 25);
    }
}
