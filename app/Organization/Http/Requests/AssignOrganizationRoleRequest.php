<?php

namespace App\Organization\Http\Requests;

use App\Models\User;
use App\Organization\Enums\OrganizationRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignOrganizationRoleRequest extends FormRequest
{
    // Authorization depends on the requested role, so it runs in the controller after validation.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role' => ['required', Rule::enum(OrganizationRole::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.exists' => 'The selected user does not exist.',
            'role.enum' => 'The role must be one of: '.implode(', ', array_column(OrganizationRole::cases(), 'value')).'.',
        ];
    }

    public function role(): OrganizationRole
    {
        return $this->enum('role', OrganizationRole::class);
    }

    public function targetUser(): User
    {
        return User::findOrFail($this->integer('user_id'));
    }
}
