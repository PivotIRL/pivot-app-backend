<?php

namespace App\Organization\Http\Requests;

use App\Organization\Enums\OrganizationRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrganizationInvitationRequest extends FormRequest
{
    // Authorization (including which role may be invited) is handled in the controller.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['nullable', Rule::enum(OrganizationRole::class)],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ];
    }

    public function role(): OrganizationRole
    {
        return $this->enum('role', OrganizationRole::class) ?? OrganizationRole::ORG_MEMBER;
    }

    public function invitationData(): array
    {
        return [
            'email' => $this->string('email')->value(),
            'role' => $this->role(),
            'expires_in_days' => $this->integer('expires_in_days') ?: null,
        ];
    }
}
