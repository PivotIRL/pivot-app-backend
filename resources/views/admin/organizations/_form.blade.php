@php
    $pricing = $organization->pricing_config ?? [];
    $entitlementRows = old('entitlements', $organization->exists
        ? $organization->entitlements->map(fn ($entitlement) => ['feature_key' => $entitlement->feature_key, 'value_json' => $entitlement->value_json])->all()
        : []);
    if ($entitlementRows === [] || $entitlementRows === null) {
        $entitlementRows = [['feature_key' => '', 'value_json' => '']];
    }
    $selectedPackage = old('package_code', $organization->package_code?->value ?? 'STANDARD');
    $selectedType = old('organization_type', $organization->organization_type?->value ?? 'CORPORATE');
    $selectedTimezone = old('timezone', $organization->timezone ?? 'UTC');
@endphp

@csrf

<h4 class="org-section-title" style="margin-top: 0;">Organization</h4>
<div class="org-form-grid">
    <div class="form-group">
        <label class="form-label" for="org-name">Name *</label>
        <input type="text" id="org-name" name="name" class="form-control" value="{{ old('name', $organization->name) }}" maxlength="255" required>
        @error('name')<small class="org-error">{{ $message }}</small>@enderror
    </div>
    <div class="form-group">
        <label class="form-label" for="org-slug">Slug * <span>(letters, numbers, dashes)</span></label>
        <input type="text" id="org-slug" name="slug" class="form-control" value="{{ old('slug', $organization->slug) }}" maxlength="255" pattern="[A-Za-z0-9_\-]+" required data-auto="{{ $organization->exists ? '0' : '1' }}">
        @error('slug')<small class="org-error">{{ $message }}</small>@enderror
    </div>
    <div class="form-group">
        <label class="form-label" for="org-type">Organization type *</label>
        <select id="org-type" name="organization_type" class="form-control" required>
            @foreach($types as $type)
            <option value="{{ $type->value }}" @selected($selectedType === $type->value)>{{ ucfirst(strtolower($type->value)) }}</option>
            @endforeach
        </select>
        @error('organization_type')<small class="org-error">{{ $message }}</small>@enderror
    </div>
    <div class="form-group">
        <label class="form-label" for="org-account-type">Account type <span>(optional)</span></label>
        <input type="text" id="org-account-type" name="account_type" class="form-control" value="{{ old('account_type', $organization->account_type) }}" maxlength="100">
        @error('account_type')<small class="org-error">{{ $message }}</small>@enderror
    </div>
    <div class="form-group">
        <label class="form-label" for="org-timezone">Timezone</label>
        <select id="org-timezone" name="timezone" class="form-control">
            @foreach($timezones as $timezone)
            <option value="{{ $timezone }}" @selected($selectedTimezone === $timezone)>{{ $timezone }}</option>
            @endforeach
        </select>
        @error('timezone')<small class="org-error">{{ $message }}</small>@enderror
    </div>
    <div class="form-group">
        <label class="form-label" for="org-country">Country code <span>(optional, 2 letters)</span></label>
        <input type="text" id="org-country" name="country_code" class="form-control" value="{{ old('country_code', $organization->country_code) }}" maxlength="2" placeholder="AU" style="text-transform: uppercase;">
        @error('country_code')<small class="org-error">{{ $message }}</small>@enderror
    </div>
</div>

<h4 class="org-section-title">Contract</h4>
<div class="org-form-grid">
    <div class="form-group">
        <label class="form-label" for="org-package">Package *</label>
        <select id="org-package" name="package_code" class="form-control" required>
            @foreach($packages as $package)
            <option value="{{ $package->value }}" @selected($selectedPackage === $package->value)>{{ ucfirst(strtolower($package->value)) }}</option>
            @endforeach
        </select>
        @error('package_code')<small class="org-error">{{ $message }}</small>@enderror
    </div>
    <div class="form-group">
        <label class="form-label" for="org-seats">Seat limit *</label>
        <input type="number" id="org-seats" name="seat_limit" class="form-control" value="{{ old('seat_limit', $organization->seat_limit) }}" min="1" required>
        @error('seat_limit')<small class="org-error">{{ $message }}</small>@enderror
    </div>
    <div class="form-group">
        <label class="form-label" for="org-start">Contract start *</label>
        <input type="datetime-local" id="org-start" name="contract_start_at" class="form-control" value="{{ old('contract_start_at', $organization->contract_start_at?->format('Y-m-d\TH:i')) }}" required>
        <small class="org-cell-sub">A future start date makes the organization "pending" until then.</small>
        @error('contract_start_at')<small class="org-error">{{ $message }}</small>@enderror
    </div>
    <div class="form-group">
        <label class="form-label" for="org-end">Contract end *</label>
        <input type="datetime-local" id="org-end" name="contract_end_at" class="form-control" value="{{ old('contract_end_at', $organization->contract_end_at?->format('Y-m-d\TH:i')) }}" required>
        @error('contract_end_at')<small class="org-error">{{ $message }}</small>@enderror
    </div>
</div>

<h4 class="org-section-title">Pricing <span style="color: var(--text-muted); font-weight: 400; font-size: .8rem;">(optional)</span></h4>
<div class="org-form-grid">
    <div class="form-group">
        <label class="form-label" for="org-currency">Currency <span>(3 letters)</span></label>
        <input type="text" id="org-currency" name="pricing_config[currency]" class="form-control" value="{{ old('pricing_config.currency', $pricing['currency'] ?? '') }}" maxlength="3" placeholder="AUD" style="text-transform: uppercase;">
        @error('pricing_config.currency')<small class="org-error">{{ $message }}</small>@enderror
    </div>
    <div class="form-group">
        <label class="form-label" for="org-unit-price">Unit price</label>
        <input type="number" id="org-unit-price" name="pricing_config[unit_price]" class="form-control" value="{{ old('pricing_config.unit_price', $pricing['unit_price'] ?? '') }}" min="0" step="0.01">
        @error('pricing_config.unit_price')<small class="org-error">{{ $message }}</small>@enderror
    </div>
    <div class="form-group">
        <label class="form-label" for="org-discount">Discount %</label>
        <input type="number" id="org-discount" name="pricing_config[discount_percent]" class="form-control" value="{{ old('pricing_config.discount_percent', $pricing['discount_percent'] ?? '') }}" min="0" max="100" step="0.01">
        @error('pricing_config.discount_percent')<small class="org-error">{{ $message }}</small>@enderror
    </div>
    <div class="form-group">
        <label class="form-label" for="org-min-seats">Minimum seats</label>
        <input type="number" id="org-min-seats" name="pricing_config[minimum_seats]" class="form-control" value="{{ old('pricing_config.minimum_seats', $pricing['minimum_seats'] ?? '') }}" min="1">
        @error('pricing_config.minimum_seats')<small class="org-error">{{ $message }}</small>@enderror
    </div>
    <div class="form-group">
        <label class="form-label" for="org-platform-fee">Platform fee</label>
        <input type="number" id="org-platform-fee" name="pricing_config[platform_fee]" class="form-control" value="{{ old('pricing_config.platform_fee', $pricing['platform_fee'] ?? '') }}" min="0" step="0.01">
        @error('pricing_config.platform_fee')<small class="org-error">{{ $message }}</small>@enderror
    </div>
    @if(! empty($pricing['volume_bands']))
    <div class="form-group">
        <label class="form-label">Volume bands</label>
        <small class="org-cell-sub">{{ count($pricing['volume_bands']) }} volume band(s) are configured. They are kept unchanged and cannot be edited here.</small>
    </div>
    @endif
</div>

<div id="org-entitlements-section" style="{{ $selectedPackage === 'CUSTOM' ? '' : 'display: none;' }}">
    <h4 class="org-section-title">Entitlements <span style="color: var(--text-muted); font-weight: 400; font-size: .8rem;">(required for the Custom package)</span></h4>
    @error('entitlements')<div class="org-alert org-alert-danger" style="margin-bottom: .75rem;">{{ $message }}</div>@enderror
    <div id="org-entitlement-rows">
        @foreach($entitlementRows as $index => $row)
        @php
            $rowValue = $row['value_json'] ?? '';
            $rowValue = is_array($rowValue) ? json_encode($rowValue) : (string) $rowValue;
        @endphp
        <div class="org-entitlement-row">
            <div>
                <input type="text" name="entitlements[{{ $index }}][feature_key]" class="form-control" value="{{ $row['feature_key'] ?? '' }}" maxlength="100" placeholder="Feature key, e.g. advanced_reports">
                @error("entitlements.$index.feature_key")<small class="org-error">{{ $message }}</small>@enderror
            </div>
            <div>
                <input type="text" name="entitlements[{{ $index }}][value_json]" class="form-control" value="{{ $rowValue }}" placeholder='Value as JSON (optional), e.g. {"limit": 10}'>
                @error("entitlements.$index.value_json")<small class="org-error">{{ $message }}</small>@enderror
            </div>
            <button type="button" class="btn btn-secondary btn-sm" onclick="removeEntitlementRow(this)" title="Remove entitlement"><i class="fa-solid fa-xmark"></i></button>
        </div>
        @endforeach
    </div>
    <button type="button" class="btn btn-secondary btn-sm" onclick="addEntitlementRow()"><i class="fa-solid fa-plus"></i> Add entitlement</button>
</div>

<template id="org-entitlement-template">
    <div class="org-entitlement-row">
        <div><input type="text" data-name="feature_key" class="form-control" maxlength="100" placeholder="Feature key, e.g. advanced_reports"></div>
        <div><input type="text" data-name="value_json" class="form-control" placeholder='Value as JSON (optional), e.g. {"limit": 10}'></div>
        <button type="button" class="btn btn-secondary btn-sm" onclick="removeEntitlementRow(this)" title="Remove entitlement"><i class="fa-solid fa-xmark"></i></button>
    </div>
</template>

<script>
    (function() {
        const nameInput = document.getElementById('org-name');
        const slugInput = document.getElementById('org-slug');
        const packageSelect = document.getElementById('org-package');
        const entitlementsSection = document.getElementById('org-entitlements-section');

        // New organizations: fill the slug from the name until the slug is edited by hand.
        if (slugInput.dataset.auto === '1') {
            let slugTouched = slugInput.value !== '';
            slugInput.addEventListener('input', () => slugTouched = true);
            nameInput.addEventListener('input', () => {
                if (slugTouched) return;
                slugInput.value = nameInput.value.toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '')
                    .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
            });
        }

        packageSelect.addEventListener('change', () => {
            entitlementsSection.style.display = packageSelect.value === 'CUSTOM' ? '' : 'none';
        });
    })();

    let entitlementIndex = {{ count($entitlementRows) }};

    function addEntitlementRow() {
        const row = document.getElementById('org-entitlement-template').content.firstElementChild.cloneNode(true);
        row.querySelectorAll('[data-name]').forEach(input => {
            input.name = `entitlements[${entitlementIndex}][${input.dataset.name}]`;
        });
        entitlementIndex++;
        document.getElementById('org-entitlement-rows').appendChild(row);
    }

    function removeEntitlementRow(button) {
        button.closest('.org-entitlement-row').remove();
    }
</script>
