<?php

namespace App\Organization\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AcceptOrganizationInvitationRequest extends FormRequest
{
    // Any signed-in user may try; the token and the invited email are checked by the service.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:128'],
        ];
    }

    public function token(): string
    {
        return $this->string('token')->value();
    }
}
