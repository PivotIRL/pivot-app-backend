<?php

namespace App\Organization\Http\Requests;

use App\Organization\Http\Requests\Concerns\HasOrganizationValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

class PreviewOrganizationInvitationRequest extends FormRequest
{
    use HasOrganizationValidationMessages;

    // Public: the secret token is the credential.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:128'],
            'code' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{6}$/'],
        ];
    }

    public function token(): string
    {
        return $this->string('token')->value();
    }

    public function code(): ?string
    {
        return $this->filled('code') ? $this->string('code')->value() : null;
    }
}
