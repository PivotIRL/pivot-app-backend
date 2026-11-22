<?php

namespace App\Organization\Http\Requests;

use App\Organization\Http\Requests\Concerns\HasOrganizationValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Edits the member's display name on their Pivot user account.
 *
 * The email is deliberately not editable here: it is the personal account's login and password-reset address,
 * so letting an organization admin change it would allow taking over the member's personal account.
 */
class UpdateOrganizationMemberRequest extends FormRequest
{
    use HasOrganizationValidationMessages;

    // Authorization is handled by OrganizationMembershipPolicy in the controller.
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    public function memberName(): string
    {
        return $this->validated('name');
    }
}
