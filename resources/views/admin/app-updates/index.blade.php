@extends('layouts.admin')
@section('title', 'Required App Updates | Pivot Admin')
@section('content')
<link rel="stylesheet" href="{{ asset('assets/css/app-updates.css') }}">
<div class="updates-page">
    <header class="updates-header">
        <span class="updates-eyebrow">APP MANAGEMENT</span>
        <h1>Required app updates</h1>
        <p>Keep members on a supported version of Pivot. Manage update requirements separately for Android and iOS.</p>
    </header>

    @if(session('success'))
        <div class="updates-alert updates-success" role="status">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="updates-alert updates-error" role="alert">
            <strong>Your changes weren't saved.</strong>
            <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <aside class="updates-guide" aria-label="How required updates work">
        <div class="updates-guide-icon"><i class="fas fa-circle-info" aria-hidden="true"></i></div>
        <div>
            <h2>The minimum version controls who must update</h2>
            <p>Members below the minimum see the update screen. Everyone at or above it can continue using Pivot.</p>
            <div class="updates-example"><span>Minimum <strong>1.2.2</strong></span><span><strong>1.2.1</strong> must update</span><span><strong>1.3.4</strong> can continue</span></div>
            <p class="updates-hint">Publish the release in the store before raising the minimum. This policy applies to app builds that include the update check.</p>
        </div>
    </aside>

    <div class="updates-grid">
        @foreach($policies as $policy)
        @php
            $platform = $policy->platform;
            $label = $platform === 'ios' ? 'iOS' : 'Android';
            $isSubmitted = old('policy_platform') === $platform;
            $value = fn ($field) => $isSubmitted ? old($field, $policy->{$field}) : $policy->{$field};
        @endphp
        <form method="POST" action="{{ route('admin.app-updates.update', $platform) }}" class="updates-card">
            @csrf @method('PUT')
            <input type="hidden" name="revision" value="{{ $policy->revision }}">
            <input type="hidden" name="policy_platform" value="{{ $platform }}">
            <div class="updates-card-header">
                <div class="updates-platform"><span class="updates-platform-icon"><i class="fab {{ $platform === 'ios' ? 'fa-apple' : 'fa-android' }}" aria-hidden="true"></i></span><div><h2>{{ $label }}</h2><p>{{ $platform === 'ios' ? 'Apple App Store' : 'Google Play Store' }}</p></div></div>
                <span class="updates-status {{ $policy->enabled ? 'is-enabled' : '' }}">{{ $policy->enabled ? 'Enabled' : 'Disabled' }}</span>
            </div>

            <div class="updates-card-body">
                <input type="hidden" name="enabled" value="0">
                <label class="updates-enable" for="{{ $platform }}-enabled">
                    <input id="{{ $platform }}-enabled" type="checkbox" name="enabled" value="1" @checked($value('enabled'))>
                    <span><strong>Require an update</strong><span>Block access for versions below the minimum.</span></span>
                </label>

                <div class="updates-version-grid">
                    <div class="form-group">
                        <label class="form-label" for="{{ $platform }}-minimum">Minimum supported version</label>
                        <input class="form-control" id="{{ $platform }}-minimum" name="minimum_version" required value="{{ $value('minimum_version') }}" placeholder="1.2.2" aria-describedby="{{ $platform }}-minimum-help">
                        <p class="updates-hint" id="{{ $platform }}-minimum-help">Older versions must update when enabled.</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="{{ $platform }}-latest">Latest available version</label>
                        <input class="form-control" id="{{ $platform }}-latest" name="latest_version" required value="{{ $value('latest_version') }}" placeholder="1.3.6" aria-describedby="{{ $platform }}-latest-help">
                        <p class="updates-hint" id="{{ $platform }}-latest-help">The current store release. Does not force an update.</p>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="{{ $platform }}-message">Update screen message</label>
                    <textarea class="form-control" id="{{ $platform }}-message" name="message" required maxlength="1000" rows="4" aria-describedby="{{ $platform }}-message-help">{{ $value('message') }}</textarea>
                    <p class="updates-hint" id="{{ $platform }}-message-help">Shown to members who need to update. Up to 1,000 characters.</p>
                </div>
                <div class="form-group">
                    <label class="form-label" for="{{ $platform }}-url">Official store URL</label>
                    <input class="form-control" id="{{ $platform }}-url" name="store_url" type="url" maxlength="2048" value="{{ $value('store_url') }}" placeholder="{{ $platform === 'ios' ? 'https://apps.apple.com/…' : 'https://play.google.com/store/apps/details?id=…' }}" aria-describedby="{{ $platform }}-url-help">
                    <p class="updates-hint" id="{{ $platform }}-url-help">The Update button opens this {{ $platform === 'ios' ? 'App Store' : 'Google Play' }} link.</p>
                </div>
            </div>
            <footer class="updates-card-footer">
                <span class="updates-hint">Changes apply after saving.</span>
                <button type="submit" class="btn btn-primary">Save {{ $label }} policy</button>
            </footer>
        </form>
        @endforeach
    </div>
</div>
@endsection
