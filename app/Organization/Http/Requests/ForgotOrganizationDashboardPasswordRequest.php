<?php

namespace App\Organization\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ForgotOrganizationDashboardPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    // Format only: checking that the email exists would reveal who has an account.
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }
}
