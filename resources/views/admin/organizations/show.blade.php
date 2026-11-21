@extends('layouts.admin')

@section('title', $organization->name.' - Pivot Admin Dashboard')

@section('content')
<ul class="breadcrumb">
    <li><a href="{{ route('admin.dashboard') }}">Admin</a></li>
    <li><i class="fa-solid fa-chevron-right" style="font-size: 0.65rem;"></i></li>
    <li><a href="{{ route('admin.organizations.index') }}">Organizations</a></li>
    <li><i class="fa-solid fa-chevron-right" style="font-size: 0.65rem;"></i></li>
    <li style="color: var(--text-heading); font-weight: 600;">{{ $organization->name }}</li>
</ul>

@if(session('success'))
<div class="org-alert org-alert-success">{{ session('success') }}</div>
@endif

@php($pricing = $organization->pricing_config ?? [])

<div class="card">
    <div class="org-header">
        <div>
            <h2>{{ $organization->name }} @include('admin.organizations._status-badge', ['status' => $organization->status])</h2>
            <p class="org-muted">{{ $organization->slug }} · ID {{ $organization->id }}</p>
        </div>
        <div class="action-btn-group">
            <a href="{{ route('admin.organizations.index') }}" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
            <a href="{{ route('admin.organizations.edit', $organization) }}" class="btn btn-primary"><i class="fa-solid fa-pen"></i> Edit</a>
            <button type="button" class="btn btn-danger"
                data-action="{{ route('admin.organizations.destroy', $organization) }}"
                data-name="{{ $organization->name }}"
                data-members="{{ $organization->active_members_count }}"
                onclick="openDeleteOrganization(this)">
                <i class="fa-solid fa-trash"></i> Delete
            </button>
        </div>
    </div>

    <div class="org-stats">
        <div class="card org-stat">
            <div class="org-stat-label">Seats used</div>
            <div class="org-stat-value">{{ $organization->active_members_count }} / {{ $organization->seat_limit }}</div>
        </div>
        <div class="card org-stat">
            <div class="org-stat-label">All memberships</div>
            <div class="org-stat-value">{{ $organization->total_members_count }}</div>
        </div>
        <div class="card org-stat">
            <div class="org-stat-label">Pending invitations</div>
            <div class="org-stat-value">{{ $organization->pending_invitations_count }}</div>
        </div>
        <div class="card org-stat">
            <div class="org-stat-label">Redemption codes</div>
            <div class="org-stat-value">{{ $organization->redemption_codes_count }}</div>
        </div>
    </div>

    <dl class="org-details">
        <div>
            <dt>Organization type</dt>
            <dd>{{ ucfirst(strtolower($organization->organization_type?->value ?? '—')) }}</dd>
        </div>
        <div>
            <dt>Account type</dt>
            <dd>{{ $organization->account_type ?: '—' }}</dd>
        </div>
        <div>
            <dt>Package</dt>
            <dd>{{ ucfirst(strtolower($organization->package_code?->value ?? '—')) }}</dd>
        </div>
        <div>
            <dt>Contract start</dt>
            <dd>{{ $organization->contract_start_at?->format('M d, Y H:i') ?? '—' }}</dd>
        </div>
        <div>
            <dt>Contract end</dt>
            <dd>{{ $organization->contract_end_at?->format('M d, Y H:i') ?? '—' }}</dd>
        </div>
        <div>
            <dt>Timezone / Country</dt>
            <dd>{{ $organization->timezone ?: 'UTC' }} / {{ $organization->country_code ?: '—' }}</dd>
        </div>
        <div>
            <dt>Organization roles</dt>
            <dd>{{ $organization->active_role_assignments_count }} active</dd>
        </div>
        <div>
            <dt>Created</dt>
            <dd>{{ $organization->created_at?->format('M d, Y H:i') ?? '—' }}{{ $organization->creator ? ' by '.$organization->creator->name : '' }}</dd>
        </div>
        <div>
            <dt>Last updated</dt>
            <dd>{{ $organization->updated_at?->format('M d, Y H:i') ?? '—' }}</dd>
        </div>
    </dl>

    <h4 class="org-section-title">Pricing</h4>
    @if($pricing === [])
    <p class="org-muted">No pricing configured.</p>
    @else
    <dl class="org-details">
        @foreach(['currency' => 'Currency', 'unit_price' => 'Unit price', 'discount_percent' => 'Discount %', 'minimum_seats' => 'Minimum seats', 'platform_fee' => 'Platform fee'] as $key => $label)
        @isset($pricing[$key])
        <div>
            <dt>{{ $label }}</dt>
            <dd>{{ $pricing[$key] }}</dd>
        </div>
        @endisset
        @endforeach
        @if(! empty($pricing['volume_bands']))
        <div>
            <dt>Volume bands</dt>
            <dd>
                @foreach($pricing['volume_bands'] as $band)
                <span class="org-cell-sub">From {{ $band['minimum_seats'] ?? '?' }} seats: {{ $band['unit_price'] ?? '?' }}{{ isset($band['discount_percent']) ? ' ('.$band['discount_percent'].'% off)' : '' }}</span>
                @endforeach
            </dd>
        </div>
        @endif
    </dl>
    @endif

    @if($organization->package_code === \App\Organization\Enums\Package::CUSTOM)
    <h4 class="org-section-title">Entitlements</h4>
    @if($organization->entitlements->isEmpty())
    <p class="org-muted">No entitlements.</p>
    @else
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Feature key</th>
                    <th>Value</th>
                </tr>
            </thead>
            <tbody>
                @foreach($organization->entitlements as $entitlement)
                <tr>
                    <td><strong>{{ $entitlement->feature_key }}</strong></td>
                    <td><code class="org-code">{{ $entitlement->value_json === null ? '—' : json_encode($entitlement->value_json) }}</code></td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
    @endif
</div>

<div class="org-grid-2">
    <div class="card">
        <div class="org-header">
            <h3>Contracts</h3>
        </div>
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Period</th>
                        <th>Package</th>
                        <th>Seats</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($contracts as $contract)
                    <tr>
                        <td>{{ $contract->starts_at?->format('M d, Y') ?? '—' }} – {{ $contract->ends_at?->format('M d, Y') ?? '—' }}</td>
                        <td>{{ ucfirst(strtolower($contract->package_code?->value ?? '—')) }}</td>
                        <td>{{ $contract->seat_limit }}</td>
                        <td>@include('admin.organizations._status-badge', ['status' => $contract->status])</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="4" class="org-empty">No contracts.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="org-header">
            <h3>Domains</h3>
        </div>
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Domain</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($organization->domains as $domain)
                    <tr>
                        <td>
                            <strong>{{ $domain->domain }}</strong>
                            @if($domain->is_primary)<span class="org-cell-sub">Primary</span>@endif
                        </td>
                        <td>@include('admin.organizations._status-badge', ['status' => $domain->status])</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="2" class="org-empty">No domains.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card" style="margin-top: 1.5rem;">
    <div class="org-header">
        <div>
            <h3>Recent Activity</h3>
            <p class="org-muted">The 10 most recent audit log entries.</p>
        </div>
    </div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Action</th>
                    <th>By</th>
                </tr>
            </thead>
            <tbody>
                @forelse($auditLogs as $log)
                @php($actorType = $log->actor_type?->value)
                <tr>
                    <td>{{ $log->created_at?->format('M d, Y H:i') ?? '—' }}</td>
                    <td><code class="org-code">{{ $log->action?->value }}</code></td>
                    <td>
                        @if($log->actor)
                        {{ $log->actor->name }}<span class="org-cell-sub">{{ str_replace('_', ' ', $actorType) }}</span>
                        @elseif($actorType === 'admin')
                        {{ $log->metadata['admin_name'] ?? 'Pivot Admin' }}<span class="org-cell-sub">admin dashboard</span>
                        @else
                        {{ ucfirst($actorType ?? 'system') }}
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="3" class="org-empty">No activity yet.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@include('admin.organizations._delete-modal')
@include('admin.organizations._styles')
@endsection
