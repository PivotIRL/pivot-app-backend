<?php

namespace App\Organization\Http\Requests;

use App\Organization\Enums\MembershipStatus;
use App\Organization\Http\Requests\Concerns\HasOrganizationValidationMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListOrganizationMembersRequest extends FormRequest
{
    use HasOrganizationValidationMessages;

    // Authorization is handled by OrganizationPolicy in the controller.
    public function authorize(): bool
    {
        return true;
    }

    protected function enumFields(): array
    {
        return [
            'status' => MembershipStatus::class,
        ];
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(MembershipStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function status(): ?MembershipStatus
    {
        return $this->enum('status', MembershipStatus::class);
    }

    public function perPage(): int
    {
        return $this->integer('per_page', 25);
    }
}
