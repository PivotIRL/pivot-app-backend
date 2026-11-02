<?php

namespace App\Organization\Http\Requests;

use App\Organization\Enums\OrganizationType;
use App\Organization\Enums\Package;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:organizations,slug'],
            'organization_type' => ['required', Rule::enum(OrganizationType::class)],
            'account_type' => ['nullable', 'string', 'max:100'],
            'package_code' => ['required', Rule::enum(Package::class)],
            'seat_limit' => ['required', 'integer', 'min:1'],
            'contract_start_at' => ['required', 'date'],
            'contract_end_at' => ['required', 'date', 'after:contract_start_at', 'after:now'],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'country_code' => ['nullable', 'string', 'size:2'],
            ...$this->pricingRules(),
            'entitlements' => ['required_if:package_code,CUSTOM', 'array'],
            'entitlements.*.feature_key' => ['required', 'string', 'max:100', 'distinct'],
            'entitlements.*.value_json' => ['nullable', 'array'],
        ];
    }

    protected function pricingRules(): array
    {
        return [
            'pricing_config' => ['nullable', 'array'],
            'pricing_config.currency' => ['sometimes', 'string', 'size:3'],
            'pricing_config.unit_price' => ['sometimes', 'numeric', 'min:0'],
            'pricing_config.discount_percent' => ['sometimes', 'numeric', 'between:0,100'],
            'pricing_config.minimum_seats' => ['sometimes', 'integer', 'min:1'],
            'pricing_config.platform_fee' => ['sometimes', 'numeric', 'min:0'],
            'pricing_config.volume_bands' => ['sometimes', 'array'],
            'pricing_config.volume_bands.*.minimum_seats' => ['required', 'integer', 'min:1'],
            'pricing_config.volume_bands.*.unit_price' => ['required', 'numeric', 'min:0'],
            'pricing_config.volume_bands.*.discount_percent' => ['sometimes', 'numeric', 'between:0,100'],
        ];
    }
}
