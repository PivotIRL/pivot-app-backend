@extends('layouts.admin')

@section('content')
<ul class="breadcrumb">
    <li><a href="{{ route('admin.dashboard') }}">Admin</a></li>
    <li><i class="fa-solid fa-chevron-right" style="font-size: 0.65rem;"></i></li>
    <li><a href="{{ route('admin.categories.index') }}">Category Management</a></li>
    <li><i class="fa-solid fa-chevron-right" style="font-size: 0.65rem;"></i></li>
    <li style="color: var(--text-heading); font-weight: 600;">Category Details</li>
</ul>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
    <div style="display: flex; align-items: center; gap: 0.75rem;">
        <div style="width: 42px; height: 42px; border-radius: 10px; background: {{ $category->color ?? '#3B5838' }}; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 1.25rem;">
            <i class="{{ $category->icon ?? 'fa-solid fa-layer-group' }}"></i>
        </div>
        <div>
            <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-heading); margin: 0;">{{ $category->name }}</h2>
            <span class="badge {{ $category->status === 'active' ? 'badge-success' : 'badge-danger' }}" style="margin-top: 0.25rem;">
                {{ ucfirst($category->status) }}
            </span>
        </div>
    </div>

    <a href="{{ route('admin.categories.index') }}" class="btn btn-secondary">
        <i class="fa-solid fa-arrow-left"></i> Back to List
    </a>
</div>

<!-- Category Activities Table -->
<div class="card">
    <h3 style="font-size: 1.125rem; font-weight: 700; margin-bottom: 1rem;">Associated Activities ({{ $category->activities_count }})</h3>

    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Type</th>
                    <th>Duration</th>
                    <th>Tier</th>
                    <th>Energy Level</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($category->activities as $activity)
                <tr>
                    <td>
                        <strong style="color: var(--text-heading);">{{ $activity->activity_title }}</strong>
                        <div style="font-size: 0.75rem; color: var(--text-muted);">{{ $activity->subcategory ?? 'General' }}</div>
                    </td>
                    <td>{{ $activity->activity_type ?? 'N/A' }}</td>
                    <td>{{ $activity->duration_minutes ? $activity->duration_minutes . ' mins' : 'N/A' }}</td>
                    <td><span class="badge badge-info">{{ $activity->tier ?? 'Standard' }}</span></td>
                    <td>{{ $activity->energy_level ?? 'N/A' }}</td>
                    <td style="text-align: right;">
                        <a href="{{ route('admin.activities.show', ['activity' => $activity->id, 'from_category' => $category->id]) }}" class="btn btn-secondary btn-sm"><i class="fa-solid fa-eye"></i> View</a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" style="text-align: center; padding: 2rem; color: var(--text-muted);">No activities assigned to this category.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection