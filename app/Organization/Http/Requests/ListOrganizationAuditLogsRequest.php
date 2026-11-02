<?php

namespace App\Organization\Http\Requests;

use App\Organization\Enums\AuditAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListOrganizationAuditLogsRequest extends FormRequest
{
    // Authorization is handled by OrganizationPolicy::viewAuditLogs in the controller.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['nullable', Rule::enum(AuditAction::class)],
            'actor_user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function filters(): array
    {
        return $this->safe()->only(['action', 'actor_user_id', 'from', 'to']);
    }

    public function perPage(): int
    {
        return $this->integer('per_page', 25);
    }
}
