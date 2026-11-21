@extends('layouts.admin')

@section('title', 'Add Organization - Pivot Admin Dashboard')

@section('content')
<ul class="breadcrumb">
    <li><a href="{{ route('admin.dashboard') }}">Admin</a></li>
    <li><i class="fa-solid fa-chevron-right" style="font-size: 0.65rem;"></i></li>
    <li><a href="{{ route('admin.organizations.index') }}">Organizations</a></li>
    <li><i class="fa-solid fa-chevron-right" style="font-size: 0.65rem;"></i></li>
    <li style="color: var(--text-heading); font-weight: 600;">Add Organization</li>
</ul>

@if($errors->any())
<div class="org-alert org-alert-danger">
    Please fix the highlighted fields.
    @error('organization')<div>{{ $message }}</div>@enderror
</div>
@endif

<div class="card" style="max-width: 960px;">
    <div class="org-header">
        <div>
            <h3>Add Organization</h3>
            <p class="org-muted">Creates the organization and its first contract. Changes are recorded in the organization audit log.</p>
        </div>
        <a href="{{ route('admin.organizations.index') }}" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
    </div>

    <form method="POST" action="{{ route('admin.organizations.store') }}">
        @include('admin.organizations._form')

        <div style="display: flex; justify-content: flex-end; gap: .75rem; margin-top: 1.5rem;">
            <a href="{{ route('admin.organizations.index') }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Create Organization</button>
        </div>
    </form>
</div>

@include('admin.organizations._styles')
@endsection
