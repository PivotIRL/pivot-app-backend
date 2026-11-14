@extends('layouts.admin')
@section('title', 'Membership Plans | Pivot Admin')
@section('content')
<h1>Membership Plans</h1>
<p>Changes apply to existing and new members after their next app refresh. Offline devices use the last downloaded limits. Store prices and purchases are managed separately.</p>
@if(session('success')) <p role="status">{{ session('success') }}</p> @endif
@if($errors->any()) <div role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div> @endif
@php
$fields = ['daily_challenges' => 'Daily challenges', 'category_count' => 'Selected categories',
    'blocked_apps' => 'Blocked apps', 'scheduled_blocking_hours' => 'Blocking hours per day',
    'blocking_intervals' => 'Blocking intervals', 'blocked_app_usage_minutes' => 'Allowed blocked-app minutes per day',
    'team_connections' => 'Team connections'];
$unlimited = ['category_count', 'blocked_apps', 'blocking_intervals'];
@endphp
@foreach($plans as $key => $plan)
<section class="card" style="padding:24px; margin:24px 0; max-width:780px">
    <h2>{{ ucfirst($key) }}</h2>
    <form method="POST" action="{{ route('admin.membership-plans.update', $key) }}">
        @csrf @method('PUT')
        <input type="hidden" name="revision" value="{{ $plan['revision'] }}">
        <p>Leave categories, blocked apps or intervals empty for unlimited.</p>
        @foreach($fields as $field => $label)
        <div style="display:flex; justify-content:space-between; gap:16px; margin:14px 0; flex-wrap:wrap">
            <label for="{{ $key }}-{{ $field }}">{{ $label }}</label>
            <input id="{{ $key }}-{{ $field }}" name="{{ $field }}" type="number" step="1"
                min="{{ in_array($field, $unlimited) ? 1 : 0 }}"
                max="{{ $field === 'scheduled_blocking_hours' ? 24 : ($field === 'blocked_app_usage_minutes' ? 1440 : 10000) }}"
                @required(!in_array($field, $unlimited)) value="{{ $plan['limits'][$field] }}">
        </div>
        @endforeach
        @foreach(['hardcore' => 'Hardcore mode', 'full_research' => 'Full research articles and available videos'] as $field => $label)
        <div style="margin:14px 0">
            <input type="hidden" name="{{ $field }}" value="0">
            <label><input type="checkbox" name="{{ $field }}" value="1" @checked($plan['limits'][$field])> {{ $label }}</label>
        </div>
        @endforeach
        <button type="submit" class="btn btn-primary">Save {{ ucfirst($key) }}</button>
    </form>
</section>
@endforeach
@endsection
