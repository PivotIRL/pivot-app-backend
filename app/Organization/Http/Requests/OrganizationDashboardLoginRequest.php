<?php

namespace App\Organization\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OrganizationDashboardLoginRequest extends FormRequest
{
    // Public endpoint: the credentials are the authorization.
    public function authorize(): bool
    {
        return true;
    }

    // No password format rules here, so login never hints at the password policy.
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }
}
