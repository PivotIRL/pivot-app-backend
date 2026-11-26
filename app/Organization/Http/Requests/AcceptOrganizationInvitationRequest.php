<?php

namespace App\Organization\Http\Requests;

use App\Organization\Http\Requests\Concerns\HasOrganizationValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

class AcceptOrganizationInvitationRequest extends FormRequest
{
    use HasOrganizationValidationMessages;

    // Any signed-in user may try; the token or code and the invited email are checked by the service.
    public function authorize(): bool
    {
        return true;
    }

    // The link sends both values; a typed code is sent alone.
    public function rules(): array
    {
        return [
            'token' => ['required_without:code', 'nullable', 'string', 'max:128'],
            'code' => ['required_without:token', 'nullable', 'string', 'regex:/^[A-Za-z0-9]{6}$/'],
        ];
    }

    public function token(): ?string
    {
        return $this->filled('token') ? $this->string('token')->value() : null;
    }

    public function code(): ?string
    {
        return $this->filled('code') ? $this->string('code')->value() : null;
    }
}
