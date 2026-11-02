<?php

namespace App\Organization\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RedeemOrganizationCodeRequest extends FormRequest
{
    // Any signed-in user may try a code; the code itself is the authorization.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64'],
        ];
    }

    public function code(): string
    {
        return $this->string('code')->value();
    }
}
