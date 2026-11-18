<?php

namespace App\Organization\Http\Requests;

use App\Organization\Http\Requests\Concerns\HasOrganizationValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Used by suspend / reactivate / remove. A reason is required because it goes to the audit log.
 */
class OrganizationControlRequest extends FormRequest
{
    use HasOrganizationValidationMessages;

    // Authorization is handled by OrganizationPolicy in the controller.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function reason(): string
    {
        return $this->string('reason')->trim()->value();
    }
}
