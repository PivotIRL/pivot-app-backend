<?php

namespace App\Organization\Http\Requests\Concerns;

use BackedEnum;
use Illuminate\Validation\Rules\Enum;

/**
 * Shared wording for Corporate validation errors: readable field names and clear messages.
 * A field name means the same thing in every Corporate request, so one list covers them all.
 */
trait HasOrganizationValidationMessages
{
    public function attributes(): array
    {
        return [
            'account_type' => 'account type',
            'actor_user_id' => 'actor user ID',
            'code_type' => 'code type',
            'contract_end_at' => 'contract end date',
            'contract_start_at' => 'contract start date',
            'country_code' => 'country code',
            'entitlements.*.feature_key' => 'entitlement feature key',
            'entitlements.*.value_json' => 'entitlement value',
            'expires_at' => 'expiry date',
            'expires_in_days' => 'expiry period in days',
            'from' => 'from date',
            'include_subdomains' => 'include subdomains option',
            'max_uses' => 'maximum uses',
            'organization_type' => 'organization type',
            'package_code' => 'package',
            'per_page' => 'results per page',
            'pricing_config' => 'pricing configuration',
            'pricing_config.currency' => 'currency',
            'pricing_config.discount_percent' => 'discount percentage',
            'pricing_config.minimum_seats' => 'minimum seats',
            'pricing_config.platform_fee' => 'platform fee',
            'pricing_config.unit_price' => 'unit price',
            'pricing_config.volume_bands' => 'volume bands',
            'pricing_config.volume_bands.*.discount_percent' => 'volume band discount percentage',
            'pricing_config.volume_bands.*.minimum_seats' => 'volume band minimum seats',
            'pricing_config.volume_bands.*.unit_price' => 'volume band unit price',
            'seat_limit' => 'seat limit',
            'starts_at' => 'start date',
            'to' => 'to date',
            'user_id' => 'user',
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'A redemption code is required.',
            'contract_end_at.after' => 'The contract end date must be in the future and after the contract start date.',
            'country_code.size' => 'The country code must be a 2-letter code, for example AU.',
            'domain.regex' => 'Enter a valid domain, for example example.com.',
            'domain.unique' => 'This domain has already been added or is verified by another organization.',
            'email.email' => 'Enter a valid email address.',
            'entitlements.required_if' => 'Entitlements are required for the CUSTOM package.',
            'entitlements.*.feature_key.distinct' => 'Each entitlement feature key can only be used once.',
            'expires_at.after' => 'The expiry date must be after the start date.',
            'pricing_config.currency.size' => 'The currency must be a 3-letter code, for example AUD.',
            'reason.required' => 'A reason is required for this action.',
            'slug.alpha_dash' => 'The slug can only contain letters, numbers, dashes and underscores.',
            'slug.unique' => 'This slug is already used by another organization.',
            'timezone.timezone' => 'Enter a valid timezone, for example Australia/Sydney.',
            'to.after_or_equal' => 'The to date must be the same as or after the from date.',
            'token.required' => 'An invitation token is required.',
            'user_id.exists' => 'The selected user does not exist.',
            ...$this->enumMessages(),
        ];
    }

    /**
     * Enum fields whose error should list the allowed values.
     *
     * @return array<string, class-string<BackedEnum>>
     */
    protected function enumFields(): array
    {
        return [];
    }

    /**
     * Rule::enum() looks up custom messages by its class name, not by "enum".
     */
    private function enumMessages(): array
    {
        $messages = [];

        foreach ($this->enumFields() as $field => $enum) {
            $values = implode(', ', array_column($enum::cases(), 'value'));
            $messages[$field.'.'.Enum::class] = "The :attribute must be one of: {$values}.";
        }

        return $messages;
    }
}
