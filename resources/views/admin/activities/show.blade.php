@extends('layouts.admin')

@section('content')
@php
    $fromCategoryId = request()->query('from_category');
    $fromCategoryId = ctype_digit((string) $fromCategoryId) ? (int) $fromCategoryId : null;
    $backUrl = $fromCategoryId
        ? route('admin.categories.show', $fromCategoryId)
        : route('admin.activities.index');
@endphp
<ul class="breadcrumb">
    <li><a href="{{ route('admin.dashboard') }}">Admin</a></li>
    <li><i class="fa-solid fa-chevron-right" style="font-size: 0.65rem;"></i></li>
    @if($fromCategoryId)
        <li><a href="{{ route('admin.categories.index') }}">Category Management</a></li>
        <li><i class="fa-solid fa-chevron-right" style="font-size: 0.65rem;"></i></li>
        <li><a href="{{ $backUrl }}">Category Details</a></li>
    @else
        <li><a href="{{ route('admin.activities.index') }}">Activities Management</a></li>
    @endif
    <li><i class="fa-solid fa-chevron-right" style="font-size: 0.65rem;"></i></li>
    <li style="color: var(--text-heading); font-weight: 600;">Activity Details</li>
</ul>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
    <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-heading);">{{ $activity->activity_title }}</h2>
    <div style="display: flex; gap: 0.75rem;">
        <a href="{{ $backUrl }}" class="btn btn-secondary">
            <i class="fa-solid fa-arrow-left"></i> Back to List
        </a>
    </div>
</div>

<div class="grid grid-cols-3" style="gap: 1.5rem; margin-bottom: 1.5rem;">
    <!-- Main Info Card -->
    <div class="card" style="grid-column: span 2;">
        <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-heading); margin-bottom: 1rem; border-bottom: 1px solid var(--border-light); padding-bottom: 0.5rem;">
            Overview & Description
        </h3>

        <div style="margin-bottom: 1.25rem;">
            <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 0.25rem;">Description</div>
            <div style="color: var(--text-body); line-height: 1.6;">{{ $activity->description ?? 'No description provided.' }}</div>
        </div>

        <div style="margin-bottom: 1.25rem;">
            <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 0.25rem;">Instructions</div>
            <div style="color: var(--text-body); line-height: 1.6; white-space: pre-line;">{{ $activity->instruction ?? 'No specific instructions added.' }}</div>
        </div>

        @if($activity->neurodivergent_notes)
        <div style="margin-bottom: 1.25rem;">
            <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 0.25rem;">Neurodivergent Notes</div>
            <div style="color: var(--text-body); line-height: 1.6;">{{ $activity->neurodivergent_notes }}</div>
        </div>
        @endif
    </div>

    <!-- Meta Details Card -->
    <div class="card">
        <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-heading); margin-bottom: 1rem; border-bottom: 1px solid var(--border-light); padding-bottom: 0.5rem;">
            Activity Attributes
        </h3>

        <div style="display: flex; flex-direction: column; gap: 0.875rem;">
            <div>
                <span style="font-size: 0.75rem; color: var(--text-muted); display: block;">Category</span>
                <strong style="color: var(--text-heading);">{{ $activity->hobby?->name ?? 'N/A' }}</strong>
            </div>
            <div>
                <span style="font-size: 0.75rem; color: var(--text-muted); display: block;">Activity Type</span>
                <strong style="color: var(--text-heading);">{{ $activity->activity_type ?? 'N/A' }}</strong>
            </div>
            <div>
                <span style="font-size: 0.75rem; color: var(--text-muted); display: block;">Subcategory</span>
                <strong style="color: var(--text-heading);">{{ $activity->subcategory ?? 'General' }}</strong>
            </div>
            <div>
                <span style="font-size: 0.75rem; color: var(--text-muted); display: block;">Duration</span>
                <strong style="color: var(--text-heading);">{{ $activity->duration_minutes ? $activity->duration_minutes . ' mins' : 'N/A' }}</strong>
            </div>
            <div>
                <span style="font-size: 0.75rem; color: var(--text-muted); display: block;">Tier</span>
                <span class="badge badge-info">{{ $activity->tier ?? 'Standard' }}</span>
            </div>
            <div>
                <span style="font-size: 0.75rem; color: var(--text-muted); display: block;">Energy Level</span>
                <strong style="color: var(--text-heading);">{{ $activity->energy_level ?? 'N/A' }}</strong>
            </div>
            <div>
                <span style="font-size: 0.75rem; color: var(--text-muted); display: block;">Neurodivergent Friendly</span>
                <span class="badge {{ $activity->neurodivergent_friendly ? 'badge-success' : 'badge-warning' }}">
                    {{ $activity->neurodivergent_friendly ? 'Yes' : 'No' }}
                </span>
            </div>
            <div>
                <span style="font-size: 0.75rem; color: var(--text-muted); display: block;">Location / Setting</span>
                <strong style="color: var(--text-heading);">{{ $activity->location ?? 'N/A' }}</strong>
            </div>
            <div>
                <span style="font-size: 0.75rem; color: var(--text-muted); display: block;">Cost</span>
                <strong style="color: var(--text-heading);">{{ $activity->cost ?? 'Free' }}</strong>
            </div>
        </div>
    </div>
</div>
@endsection