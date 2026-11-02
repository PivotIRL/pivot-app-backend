<?php

namespace App\Organization\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Used by suspend / deactivate. A reason is required because it is stored on the membership and in the audit log.
 */
class MembershipActionRequest extends FormRequest
{
    // Authorization is handled by OrganizationMembershipPolicy in the controller.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'A reason is required for this action.',
        ];
    }

    public function reason(): string
    {
        return $this->string('reason')->trim()->value();
    }
}
