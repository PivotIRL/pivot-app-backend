<?php

namespace App\Http\Requests\Admin\Concerns;

/**
 * Turns Admin Dashboard form input into the shape the existing Organization validation rules expect:
 * blank optional fields are dropped, entitlement rows are cleaned up and their JSON values decoded.
 */
trait NormalizesOrganizationFormInput
{
    private const PRICING_FIELDS = ['currency', 'unit_price', 'discount_percent', 'minimum_seats', 'platform_fee'];

    protected function prepareForValidation(): void
    {
        $input = [];

        foreach (['slug', 'country_code', 'timezone', 'account_type'] as $field) {
            if (is_string($this->input($field))) {
                $input[$field] = trim($this->input($field));
            }
        }
        if (isset($input['slug'])) {
            $input['slug'] = strtolower($input['slug']);
        }
        if (isset($input['country_code'])) {
            $input['country_code'] = strtoupper($input['country_code']);
        }

        $input['pricing_config'] = $this->normalizedPricing();
        $input['entitlements'] = $this->normalizedEntitlements();

        $this->merge($input);

        // Entitlements only exist on the CUSTOM package; the service clears them for other packages.
        if ($this->input('package_code') !== 'CUSTOM') {
            $this->getInputSource()->remove('entitlements');
        }
        // A missing timezone falls back to the column default (UTC).
        if ($this->input('timezone') === null) {
            $this->getInputSource()->remove('timezone');
        }
    }

    /** Volume bands are not editable here; existing ones are kept. */
    protected function existingVolumeBands(): ?array
    {
        return null;
    }

    private function normalizedPricing(): ?array
    {
        $submitted = (array) $this->input('pricing_config', []);
        $pricing = [];

        foreach (self::PRICING_FIELDS as $field) {
            $value = $submitted[$field] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $pricing[$field] = $field === 'currency' ? strtoupper(trim((string) $value)) : $value;
        }

        if ($bands = $this->existingVolumeBands()) {
            $pricing['volume_bands'] = $bands;
        }

        return $pricing === [] ? null : $pricing;
    }

    private function normalizedEntitlements(): array
    {
        $rows = [];

        foreach ((array) $this->input('entitlements', []) as $row) {
            $key = trim((string) ($row['feature_key'] ?? ''));
            $value = trim((string) ($row['value_json'] ?? ''));
            if ($key === '' && $value === '') {
                continue;
            }

            $decoded = $value === '' ? null : json_decode($value, true);
            $rows[] = [
                'feature_key' => $key,
                // Invalid or non-object JSON stays a string so the "array" rule rejects it.
                'value_json' => $value === '' ? null : (is_array($decoded) ? $decoded : $value),
            ];
        }

        return $rows;
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'entitlements.*.value_json.array' => 'Each entitlement value must be a JSON object, for example {"limit": 10}.',
            'slug.alpha_dash' => 'The slug may only contain letters, numbers, dashes and underscores.',
            'slug.unique' => 'This slug is already used by another organization (including removed organizations).',
        ];
    }
}
