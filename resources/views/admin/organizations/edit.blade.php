@extends('layouts.admin')

@section('title', 'Edit '.$organization->name.' - Pivot Admin Dashboard')

@section('content')
<ul class="breadcrumb">
    <li><a href="{{ route('admin.dashboard') }}">Admin</a></li>
    <li><i class="fa-solid fa-chevron-right" style="font-size: 0.65rem;"></i></li>
    <li><a href="{{ route('admin.organizations.index') }}">Organizations</a></li>
    <li><i class="fa-solid fa-chevron-right" style="font-size: 0.65rem;"></i></li>
    <li><a href="{{ route('admin.organizations.show', $organization) }}">{{ $organization->name }}</a></li>
    <li><i class="fa-solid fa-chevron-right" style="font-size: 0.65rem;"></i></li>
    <li style="color: var(--text-heading); font-weight: 600;">Edit</li>
</ul>

@error('organization')
<div class="org-alert org-alert-danger">{{ $message }}</div>
@enderror
@if($errors->any() && ! $errors->has('organization'))
<div class="org-alert org-alert-danger">Please fix the highlighted fields.</div>
@endif

@php($isExpired = $organization->status === \App\Organization\Enums\OrganizationStatus::EXPIRED)

<div class="card" style="max-width: 960px;">
    <div class="org-header">
        <div>
            <h3>Edit {{ $organization->name }}</h3>
            <p class="org-muted">Current status: @include('admin.organizations._status-badge', ['status' => $organization->status])</p>
        </div>
        <a href="{{ route('admin.organizations.show', $organization) }}" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
    </div>

    @if($isExpired)
    <div class="org-alert org-alert-warning">
        This organization's contract has expired, so it cannot be edited here. Expired contracts must be renewed.
    </div>
    @elseif($organization->status === \App\Organization\Enums\OrganizationStatus::SUSPENDED)
    <div class="org-alert org-alert-warning">
        This organization is suspended. Editing the contract keeps it suspended.
    </div>
    @endif

    <form method="POST" action="{{ route('admin.organizations.update', $organization) }}">
        @method('PUT')
        <fieldset @disabled($isExpired) style="border: 0; padding: 0; margin: 0;">
            @include('admin.organizations._form')
        </fieldset>

        <div style="display: flex; justify-content: flex-end; gap: .75rem; margin-top: 1.5rem;">
            <a href="{{ route('admin.organizations.show', $organization) }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary" @disabled($isExpired)><i class="fa-solid fa-save"></i> Save Changes</button>
        </div>
    </form>
</div>

@include('admin.organizations._styles')
@endsection
