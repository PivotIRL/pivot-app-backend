<?php

namespace App\Organization\Http\Requests;

use App\Organization\Http\Requests\Concerns\HasOrganizationValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The full invitation link the app received (from the email, or from the page's "Open in Pivot app" button).
 */
class AcceptMemberInvitationLinkRequest extends FormRequest
{
    use HasOrganizationValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'link' => ['required', 'string', 'max:2048'],
        ];
    }

    public function invitationLink(): string
    {
        return $this->string('link')->value();
    }
}
