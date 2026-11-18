<?php

namespace App\Organization\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Used for both first-time setup and password reset. The emailed token is the authorization.
 */
class ResetOrganizationDashboardPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:72', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ];
    }
}
