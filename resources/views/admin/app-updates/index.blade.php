@extends('layouts.admin')
@section('title', 'Required App Updates | Pivot Admin')
@section('content')
<h1>Required app updates</h1>
<p>Only builds containing the update check follow this policy. Users below the minimum supported version see only the update screen. Users at or above the minimum can continue, even if a newer release exists.</p>
<p>Example: minimum 1.2.2 and latest 1.3.6 require 1.2.1 and older to update, while 1.3.4 continues normally. Publish the update in the store before raising the minimum.</p>
@if(session('success'))<p role="status">{{ session('success') }}</p>@endif
@if($errors->any())<ul role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
@foreach($policies as $policy)
<form method="POST" action="{{ route('admin.app-updates.update', $policy->platform) }}" style="padding:24px; background:white; border-radius:16px; max-width:760px; margin:24px 0">
    @csrf @method('PUT')
    <h2>{{ $policy->platform === 'ios' ? 'iOS' : 'Android' }}</h2>
    <input type="hidden" name="revision" value="{{ $policy->revision }}">
    <input type="hidden" name="enabled" value="0">
    <p><label><input type="checkbox" name="enabled" value="1" @checked($policy->enabled)> Require unsupported versions to update</label></p>
    <p><label>Minimum supported version <input name="minimum_version" required value="{{ $policy->minimum_version }}" placeholder="1.2.2"></label></p>
    <p><label>Latest available version <input name="latest_version" required value="{{ $policy->latest_version }}" placeholder="1.3.6"></label></p>
    <p><label>Update message<br><textarea name="message" required maxlength="1000" rows="3" style="width:100%">{{ $policy->message }}</textarea></label></p>
    <p><label>Official store URL<br><input name="store_url" type="url" value="{{ $policy->store_url }}" style="width:100%"></label></p>
    <button type="submit" class="btn btn-primary">Save {{ $policy->platform === 'ios' ? 'iOS' : 'Android' }} policy</button>
</form>
@endforeach
@endsection
