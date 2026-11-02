<?php

namespace App\Organization\Http\Requests;

use App\Organization\Enums\OrganizationType;
use App\Organization\Enums\Package;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

class UpdateOrganizationRequest extends StoreOrganizationRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['name'] = ['sometimes', 'string', 'max:255'];
        $rules['slug'] = ['sometimes', 'string', 'max:255', 'alpha_dash', Rule::unique('organizations', 'slug')->ignore($this->route('organization'))];
        $rules['organization_type'] = ['sometimes', Rule::enum(OrganizationType::class)];
        $rules['package_code'] = ['sometimes', Rule::enum(Package::class)];
        $rules['seat_limit'] = ['sometimes', 'integer', 'min:1'];
        $rules['contract_start_at'] = ['sometimes', 'date'];
        $rules['contract_end_at'] = ['sometimes', 'date', 'after:now'];
        $rules['entitlements'] = ['sometimes', 'required_if:package_code,CUSTOM', 'array'];
        $rules['timezone'] = ['sometimes', 'string', 'timezone:all'];

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $organization = $this->route('organization');
            $start = $this->input('contract_start_at', $organization?->contract_start_at);
            $end = $this->input('contract_end_at', $organization?->contract_end_at);

            if ($start && $end && strtotime((string) $end) <= strtotime((string) $start)) {
                $validator->errors()->add('contract_end_at', 'The contract end date must be after the start date.');
            }
        });
    }
}
