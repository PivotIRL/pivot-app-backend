<?php

namespace App\Organization\Http\Requests;

use App\Organization\Enums\InvitationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListOrganizationInvitationsRequest extends FormRequest
{
    // Authorization is handled by OrganizationPolicy in the controller.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(InvitationStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function status(): ?InvitationStatus
    {
        return $this->enum('status', InvitationStatus::class);
    }

    public function perPage(): int
    {
        return $this->integer('per_page', 25);
    }
}
