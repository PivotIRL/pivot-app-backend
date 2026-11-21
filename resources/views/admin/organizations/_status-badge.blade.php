@php
    $statusValue = $status instanceof \BackedEnum ? $status->value : (string) $status;
    $badgeClass = match ($statusValue) {
        'active', 'verified' => 'badge-success',
        'pending' => 'badge-info',
        'suspended' => 'badge-warning',
        'expired' => 'badge-danger',
        default => 'badge-olive',
    };
@endphp
<span class="badge {{ $badgeClass }}">{{ ucfirst($statusValue) }}</span>
