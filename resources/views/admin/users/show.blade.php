@extends('layouts.admin')

@section('content')
<ul class="breadcrumb">
    <li><a href="{{ route('admin.dashboard') }}">Admin</a></li>
    <li><i class="fa-solid fa-chevron-right" style="font-size: 0.65rem;"></i></li>
    <li><a href="{{ route('admin.users.index') }}">Users Directory</a></li>
    <li><i class="fa-solid fa-chevron-right" style="font-size: 0.65rem;"></i></li>
    <li style="color: var(--text-heading); font-weight: 600;">{{ $user->name }}</li>
</ul>

<div class="card" style="margin-bottom: 1.5rem;">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem;">
        <div style="display: flex; gap: 1.5rem; align-items: center;">
            <div style="width: 80px; height: 80px; border-radius: 50%; background: var(--bg-hover); display: flex; align-items: center; justify-content: center; font-size: 2rem; color: var(--primary);">
                @if($user->userProfile && $user->userProfile->avatar_url)
                <img src="{{ $user->userProfile->avatar_url }}" alt="{{ $user->name }}" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                @else
                {{ substr($user->name, 0, 1) }}
                @endif
            </div>
            <div>
                <h2 style="font-size: 1.5rem; font-weight: 700; margin-bottom: 0.25rem;">{{ $user->name }}</h2>
                <div style="color: var(--text-muted); display: flex; gap: 1rem; font-size: 0.875rem;">
                    <span><i class="fa-solid fa-envelope"></i> {{ $user->email }}</span>
                    <span>
                        <i class="fa-brands fa-{{ $user->provider === 'email' ? 'envelope' : $user->provider }}"></i>
                        {{ ucfirst($user->provider) }}
                    </span>
                    <span class="badge {{ $user->status === 'active' ? 'badge-success' : 'badge-danger' }}">
                        {{ ucfirst($user->status) }}
                    </span>
                </div>
            </div>
        </div>
        <div style="display: flex; gap: 0.5rem;">
            <a href="{{ route('admin.users.index') }}" class="btn btn-secondary">
                <i class="fa-solid fa-arrow-left"></i> Back to Users
            </a>
        </div>
    </div>
</div>

<div class="grid grid-cols-2" style="gap: 1.5rem; margin-bottom: 1.5rem;">
    <div class="card">
        <h3 style="font-size: 1.125rem; font-weight: 700; margin-bottom: 1rem;">Profile Details</h3>
        <table class="data-table" style="width: 100%; text-align: left;">
            <tbody>
                <tr>
                    <td style="font-weight: 600; width: 40%; border:none; padding: 0.5rem 0;">Registered</td>
                    <td style="border:none; padding: 0.5rem 0;">{{ $user->created_at->format('M d, Y') }}</td>
                </tr>
                <tr>
                    <td style="font-weight: 600; border:none; padding: 0.5rem 0;">Last Login</td>
                    <td style="border:none; padding: 0.5rem 0;">{{ $user->last_login_at ? $user->last_login_at->format('M d, Y h:i A') : 'N/A' }}</td>
                </tr>
                @if($user->userProfile)
                <tr>
                    <td style="font-weight: 600; border:none; padding: 0.5rem 0;">Bio</td>
                    <td style="border:none; padding: 0.5rem 0;">{{ $user->userProfile->bio ?? 'N/A' }}</td>
                </tr>
                @endif
            </tbody>
        </table>
    </div>

    <div class="card">
        <h3 style="font-size: 1.125rem; font-weight: 700; margin-bottom: 1rem;">Subscription Status</h3>
        @php
            $active = in_array($membership['access_status'], ['active', 'trial'], true);
            $plan = $active ? $membership['effective_package'] : $membership['selected_package'];
            $period = $membership['payment_path'];
            $label = $plan ? ucfirst($plan) . ($period ? ' ' . ucfirst($period) : '') : 'No membership';
        @endphp
        <div style="background: var(--bg-hover); padding: 1rem; border-radius: 0.5rem; border-left: 4px solid var(--{{ $active ? 'primary' : 'border-color' }});">
            <div style="font-weight: 600; margin-bottom: 0.25rem;">{{ $label }}</div>
            <div>Status: {{ ucfirst(str_replace('_', ' ', $membership['access_status'])) }}</div>
            <div>Environment: {{ ucfirst($membershipEnvironment) }}</div>
            @if($membership['access_source'])
                <div>Source: {{ ucfirst(str_replace('_', ' ', $membership['access_source'])) }}</div>
            @endif
            @if($membership['access_ends_at'])
                <div>{{ $active ? 'Access until' : 'Access ended' }}: {{ \Illuminate\Support\Carbon::parse($membership['access_ends_at'])->utc()->format('M d, Y H:i') }} UTC</div>
            @elseif($active && $period === 'lifetime')
                <div>No expiry</div>
            @endif
            <div style="font-size: 0.875rem; color: var(--text-muted); margin-top: 0.5rem;">Latest verified membership record</div>
        </div>
    </div>
</div>

<div class="grid grid-cols-2" style="gap: 1.5rem;">
    <div class="card">
        <h3 style="font-size: 1.125rem; font-weight: 700; margin-bottom: 1rem;">User Hobbies ({{ $user->hobbies->count() }})</h3>
        @if($user->hobbies->count() > 0)
        <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
            @foreach($user->hobbies as $hobby)
            <span class="badge" style="background: var(--bg-hover); color: var(--text-heading); border: 1px solid var(--border-color);">
                {{ $hobby->name }}
            </span>
            @endforeach
        </div>
        @else
        <p style="color: var(--text-muted); font-size: 0.875rem;">No hobbies associated.</p>
        @endif
    </div>

    <div class="card">
        <h3 style="font-size: 1.125rem; font-weight: 700; margin-bottom: 1rem;">User Activities</h3>
        @if($user->activities->count() > 0)
        <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.75rem;">
            @foreach($user->activities->take(5) as $activity)
            <li style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 0.75rem; border-bottom: 1px solid var(--border-color);">
                <div>
                    <div style="font-weight: 600; font-size: 0.875rem;">{{ $activity->activity_title ?? 'Unnamed Activity' }}</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">{{ $activity->duration_minutes }} mins</div>
                </div>
            </li>
            @endforeach
        </ul>
        @else
        <p style="color: var(--text-muted); font-size: 0.875rem;">No activities associated.</p>
        @endif
    </div>

    <div class="card">
        @php $groupChallenges = $user->groupChallengeParticipants->sortByDesc('updated_at'); @endphp
        <h3 style="font-size: 1.125rem; font-weight: 700; margin-bottom: 1rem;">Group Challenges</h3>
        @if($groupChallenges->count() > 0)
        <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.75rem;">
            @foreach($groupChallenges->take(5) as $participant)
            <li style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 0.75rem; border-bottom: 1px solid var(--border-color);">
                <div>
                    <div style="font-weight: 600; font-size: 0.875rem;">{{ $participant->session?->challenge?->activity_title ?? 'Group Challenge' }}</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">{{ ucfirst(str_replace('_', ' ', $participant->invite_status)) }} · Progress: {{ $participant->progress }}%</div>
                </div>
            </li>
            @endforeach
        </ul>
        @else
        <p style="color: var(--text-muted); font-size: 0.875rem;">No group challenges associated.</p>
        @endif
    </div>
</div>
@endsection