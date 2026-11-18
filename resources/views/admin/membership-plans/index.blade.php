@extends('layouts.admin')
@section('title', 'Membership Plans | Pivot Admin')
@section('content')
<link rel="stylesheet" href="{{ asset('assets/css/membership-plans.css') }}">
<div class="membership-settings">
    <nav aria-label="Breadcrumb" class="membership-breadcrumb">
        <a href="{{ route('admin.subscriptions.index') }}">Subscriptions</a>
        <span aria-hidden="true">/</span><span>Membership plans</span>
    </nav>
    <header class="membership-heading">
        <div><span class="membership-eyebrow">MEMBERSHIP SETTINGS</span>
            <h1>Membership Plans</h1>
            <p>Shape what members can do. Set limits and features for each plan.</p>
        </div>
        <a class="btn btn-secondary" href="{{ route('admin.subscriptions.index') }}"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Subscriptions</a>
    </header>
    <aside class="membership-notice">
        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
        <div><strong>Updated on the next app refresh</strong><p>Changes apply to existing and new members. Offline devices keep their last downloaded limits. Store prices and purchases are managed separately.</p></div>
    </aside>
    @if(session('success')) <div class="membership-feedback membership-feedback--success" role="status"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> {{ session('success') }}</div> @endif
    @if($errors->any()) <div class="membership-feedback membership-feedback--error" role="alert"><strong>We couldn't save your changes.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div> @endif
    @php
        $groups = [
            'Daily activity' => [
                'daily_challenges' => ['Daily challenges', 'Challenges available each day', 'per day'],
                'category_count' => ['Selected categories', 'Categories a member can choose', 'categories'],
                'team_connections' => ['Team connections', 'Maximum team connections', 'connections'],
            ],
            'App blocking' => [
                'blocked_apps' => ['Blocked apps', 'Apps a member can block', 'apps'],
                'scheduled_blocking_hours' => ['Blocking hours', 'Scheduled blocking time each day', 'hrs / day'],
                'blocking_intervals' => ['Blocking intervals', 'Number of blocking intervals', 'intervals'],
                'blocked_app_usage_minutes' => ['Allowed app usage', 'Time allowed in blocked apps each day', 'min / day'],
            ],
        ];
        $unlimited = ['category_count', 'blocked_apps', 'blocking_intervals'];
    @endphp
    <div class="membership-grid">
    @foreach(['standard', 'premium'] as $key)
        @continue(!isset($plans[$key]))
        @php $plan = $plans[$key]; @endphp
        <section class="membership-plan membership-plan--{{ $key }}" aria-labelledby="{{ $key }}-title">
            <header class="membership-plan-header">
                <span class="membership-plan-icon"><i class="fa-solid {{ $key === 'premium' ? 'fa-crown' : 'fa-leaf' }}" aria-hidden="true"></i></span>
                <div><h2 id="{{ $key }}-title">{{ ucfirst($key) }}</h2><p>{{ $key === 'premium' ? 'More flexibility. Expanded possibilities.' : 'Everyday essentials for building better habits.' }}</p></div>
            </header>
            <form method="POST" action="{{ route('admin.membership-plans.update', $key) }}">
                @csrf @method('PUT')
                <input type="hidden" name="revision" value="{{ $plan['revision'] }}">
                <div class="membership-plan-body">
                    <p class="membership-unlimited"><i class="fa-solid fa-infinity" aria-hidden="true"></i> Leave categories, apps or intervals blank for unlimited.</p>
                    @foreach($groups as $group => $fields)
                    <fieldset class="membership-group">
                        <legend>{{ $group }}</legend>
                        @foreach($fields as $field => [$label, $hint, $unit])
                        <div class="membership-field">
                            <div><label for="{{ $key }}-{{ $field }}">{{ $label }}</label><p id="{{ $key }}-{{ $field }}-hint">{{ $hint }}</p></div>
                            <div class="membership-input">
                                <input id="{{ $key }}-{{ $field }}" name="{{ $field }}" type="number" step="1"
                                    aria-describedby="{{ $key }}-{{ $field }}-hint"
                                    min="{{ in_array($field, $unlimited) ? 1 : 0 }}"
                                    max="{{ $field === 'scheduled_blocking_hours' ? 24 : ($field === 'blocked_app_usage_minutes' ? 1440 : 10000) }}"
                                    @required(!in_array($field, $unlimited))
                                    placeholder="{{ in_array($field, $unlimited) ? 'Unlimited' : '' }}"
                                    value="{{ $plan['limits'][$field] }}">
                                <span aria-hidden="true">{{ $unit }}</span>
                            </div>
                        </div>
                        @endforeach
                    </fieldset>
                    @endforeach
                    <fieldset class="membership-group membership-features">
                        <legend>Included features</legend>
                        @foreach(['hardcore' => ['Hardcore mode', 'Access to stricter app blocking'], 'full_research' => ['Full research access', 'Full articles and available videos']] as $field => [$label, $hint])
                        <label class="membership-feature" for="{{ $key }}-{{ $field }}">
                            <span><strong>{{ $label }}</strong><small>{{ $hint }}</small></span>
                            <input type="hidden" name="{{ $field }}" value="0">
                            <input class="membership-toggle" id="{{ $key }}-{{ $field }}" type="checkbox" name="{{ $field }}" value="1" @checked($plan['limits'][$field])>
                        </label>
                        @endforeach
                    </fieldset>
                </div>
                <footer class="membership-plan-footer"><span>Applies to all {{ $key }} members</span><button type="submit" class="btn btn-primary"><i class="fa-solid fa-check" aria-hidden="true"></i> Save {{ ucfirst($key) }}</button></footer>
            </form>
        </section>
    @endforeach
    </div>
</div>
@endsection
