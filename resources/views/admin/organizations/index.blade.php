@extends('layouts.admin')

@section('title', 'Organizations - Pivot Admin Dashboard')

@section('content')
<ul class="breadcrumb">
    <li><a href="{{ route('admin.dashboard') }}">Admin</a></li>
    <li><i class="fa-solid fa-chevron-right" style="font-size: 0.65rem;"></i></li>
    <li style="color: var(--text-heading); font-weight: 600;">Organizations</li>
</ul>

@if(session('success'))
<div class="org-alert org-alert-success">{{ session('success') }}</div>
@endif

@if($errors->any())
<div class="org-alert org-alert-danger">
    <ul>
        @foreach($errors->all() as $error)
        <li>{{ $error }}</li>
        @endforeach
    </ul>
</div>
@endif

<div class="card">
    <div class="org-header">
        <div>
            <h3>Organizations</h3>
            <p class="org-muted">Corporate customers, their contracts and seats.</p>
        </div>
        <a href="{{ route('admin.organizations.create') }}" class="btn btn-primary">
            <i class="fa-solid fa-plus"></i> Add Organization
        </a>
    </div>

    <form method="GET" action="{{ route('admin.organizations.index') }}" style="display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; align-items: center;">
        <div style="position: relative; flex: 1; max-width: 320px;">
            <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 0.875rem; top: 50%; transform: translateY(-50%); color: var(--text-muted); pointer-events: none;"></i>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Search name or slug..." class="form-control" style="padding-left: 2.375rem;" oninput="debouncedSubmit(this, 450)">
        </div>
        <select name="status" class="form-control" style="max-width: 200px;" onchange="this.form.submit()">
            <option value="">All statuses</option>
            @foreach($statuses as $status)
            <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ ucfirst($status->value) }}</option>
            @endforeach
        </select>
        @if(request('search') || request('status'))
        <a href="{{ route('admin.organizations.index') }}" class="btn btn-secondary btn-sm" style="height: 38px; padding: 0 0.875rem; display: inline-flex; align-items: center;">
            <i class="fa-solid fa-xmark"></i> Clear
        </a>
        @endif
    </form>

    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Organization</th>
                    <th>Type</th>
                    <th>Package</th>
                    <th>Seats</th>
                    <th>Contract</th>
                    <th>Status</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($organizations as $organization)
                <tr>
                    <td>
                        <a href="{{ route('admin.organizations.show', $organization) }}" style="font-weight: 600; color: var(--text-heading);">{{ $organization->name }}</a>
                        <span class="org-cell-sub">{{ $organization->slug }}</span>
                    </td>
                    <td>{{ ucfirst(strtolower($organization->organization_type?->value ?? '—')) }}</td>
                    <td>{{ ucfirst(strtolower($organization->package_code?->value ?? '—')) }}</td>
                    <td>{{ $organization->active_members_count }} / {{ $organization->seat_limit }}</td>
                    <td>
                        {{ $organization->contract_start_at?->format('M d, Y') ?? '—' }} – {{ $organization->contract_end_at?->format('M d, Y') ?? '—' }}
                    </td>
                    <td>@include('admin.organizations._status-badge', ['status' => $organization->status])</td>
                    <td style="text-align: right;">
                        <div class="action-btn-group">
                            <a href="{{ route('admin.organizations.show', $organization) }}" class="btn btn-secondary btn-sm" title="View"><i class="fa-solid fa-eye"></i></a>
                            <a href="{{ route('admin.organizations.edit', $organization) }}" class="btn btn-secondary btn-sm" title="Edit"><i class="fa-solid fa-pen"></i></a>
                            <button type="button" class="btn btn-danger btn-sm" title="Delete"
                                data-action="{{ route('admin.organizations.destroy', $organization) }}"
                                data-name="{{ $organization->name }}"
                                data-members="{{ $organization->active_members_count }}"
                                onclick="openDeleteOrganization(this)">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="org-empty">No organizations found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div style="margin-top: 1.25rem;">
        {{ $organizations->links('partials.pagination') }}
    </div>
</div>

@include('admin.organizations._delete-modal')
@include('admin.organizations._styles')
@endsection
