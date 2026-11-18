<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Services\Membership\MembershipWebhookPolicy;

class RevenueCatWebhookRequest extends FormRequest
{
    private const EVENT_TYPES = MembershipWebhookPolicy::EVENTS;

    private const SUPPORTED_IOS_TIER_PRODUCTS = [
        'tier_2_ios',
        'tier_3_ios',
        'tier_2:tier2' => 2,
        'tier_3:tier3' => 3,
    ];

    private const SUPPORTED_ANDROID_TIER_PRODUCTS = [
        'standard_android_monthly',
        'standard_android_annual',
        'standard_android_lifetime',
        'premium_android_monthly',
        'premium_android_annual',
        'premium_android_lifetime',
        'tier_2',
        'tier_3',
        'tier_2_android',
        'tier_3_android',
        'standard_android',
        'standard_lifetime_android',
        'premium_android',
        'premium_lifetime_android',
        'tier_2_annual',
        'tier_2_lifetime',
        'tier_3_annual',
        'tier_3_lifetime',
    ];

    protected function prepareForValidation(): void
    {
        $payload = $this->input('event');

        if (is_array($payload)) {
            $this->merge($payload);
        }
    }

    public function authorize(): bool
    {
        return true;
    }


    public function rules(): array
    {
        return [
            'id' => ['required', 'string', 'max:255'],
            'app_user_id' => ['required_unless:type,TEST,TRANSFER', 'nullable', 'string'],
            'transferred_from' => ['required_if:type,TRANSFER', 'array', 'min:1'],
            'transferred_from.*' => ['string'],
            'transferred_to' => ['required_if:type,TRANSFER', 'array', 'min:1'],
            'transferred_to.*' => ['string'],
            'original_app_user_id' => ['nullable', 'string'],
            'aliases' => ['nullable', 'array'],
            'aliases.*' => ['string'],
            'product_id' => [
                'nullable',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || $value === '') {
                        return;
                    }

                    if (
                        str_ends_with($value, '_ios') && str_starts_with($value, 'tier_')
                        && ! in_array($value, self::SUPPORTED_IOS_TIER_PRODUCTS, true)
                    ) {
                        $fail("The {$attribute} is not a supported iOS tier product.");
                    }

                    if ((str_ends_with($value, '_android') || preg_match('/^tier_\d+$/', $value) === 1)
                        && ! in_array($value, self::SUPPORTED_ANDROID_TIER_PRODUCTS, true)
                    ) {
                        $fail("The {$attribute} is not a supported Android tier product.");
                    }
                },
            ],
            'entitlement_ids' => ['nullable', 'array'],
            'entitlement_ids.*' => ['string', 'max:255'],
            'type' => ['required', Rule::in(self::EVENT_TYPES)],
            'new_product_id' => [
                'nullable',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || $value === '') {
                        return;
                    }

                    if (
                        str_ends_with($value, '_ios') && str_starts_with($value, 'tier_')
                        && ! in_array($value, self::SUPPORTED_IOS_TIER_PRODUCTS, true)
                    ) {
                        $fail("The {$attribute} is not a supported iOS tier product.");
                    }

                    if ((str_ends_with($value, '_android') || preg_match('/^tier_\d+$/', $value) === 1)
                        && ! in_array($value, self::SUPPORTED_ANDROID_TIER_PRODUCTS, true)
                    ) {
                        $fail("The {$attribute} is not a supported Android tier product.");
                    }
                },
            ],
            'environment' => ['nullable', 'string', 'max:50'],
            'active' => ['nullable', 'boolean'],
            'store' => ['nullable', 'string', 'max:50'],
            'purchased_at_ms' => ['nullable', 'numeric'],
            'expiration_at_ms' => ['nullable', 'numeric'],
            'event_timestamp_ms' => ['nullable', 'numeric'],
            'transaction_id' => ['nullable', 'string', 'max:255'],
            'original_transaction_id' => ['nullable', 'string', 'max:255'],
            'base_plan_id' => ['nullable', 'string', 'max:100'],
            'period_type' => ['nullable', 'string', 'max:50'],
            'auto_renew' => ['nullable', 'boolean'],
            'cancel_reason' => ['nullable', 'string', 'max:100'],
        ];
    }
}
