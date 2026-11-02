<?php

namespace App\Organization\Http\Requests;

use App\Organization\Enums\OrganizationDomainStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrganizationDomainRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('domain'))) {
            $this->merge(['domain' => strtolower(trim($this->input('domain')))]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'domain' => [
                'required',
                'string',
                'max:253',
                'regex:/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}$/',
                // Pending claims by other organizations do not block; only a verified domain is taken.
                Rule::unique('organization_domains', 'domain')
                    ->where('organization_id', $this->route('organization')?->getKey()),
                Rule::unique('organization_domains', 'domain')
                    ->where('status', OrganizationDomainStatus::VERIFIED->value),
            ],
            'include_subdomains' => ['sometimes', 'boolean'],
        ];
    }
}
