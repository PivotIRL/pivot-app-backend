<?php

namespace App\Organization\Http\Requests;

use App\Organization\Http\Requests\Concerns\HasOrganizationValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

class SendMemberInvitationLinkRequest extends FormRequest
{
    use HasOrganizationValidationMessages;

    // Authorization is handled by OrganizationPolicy::manageInvitations in the controller.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ];
    }

    public function invitedEmail(): string
    {
        return $this->string('email')->value();
    }

    public function expiresInDays(): ?int
    {
        return $this->filled('expires_in_days') ? $this->integer('expires_in_days') : null;
    }
}
