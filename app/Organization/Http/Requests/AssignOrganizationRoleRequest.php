<?php

namespace App\Organization\Http\Requests;

use App\Models\User;
use App\Organization\Enums\OrganizationRole;
use App\Organization\Http\Requests\Concerns\HasOrganizationValidationMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignOrganizationRoleRequest extends FormRequest
{
    use HasOrganizationValidationMessages;

    // Authorization depends on the requested role, so it runs in the controller after validation.
    public function authorize(): bool
    {
        return true;
    }

    protected function enumFields(): array
    {
        return [
            'role' => OrganizationRole::class,
        ];
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role' => ['required', Rule::enum(OrganizationRole::class)],
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
