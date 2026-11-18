@extends('layouts.admin')

@section('title', 'Subscriptions | Pivot Admin')

@section('content')
<a href="{{ route('admin.membership-plans.index') }}" class="btn btn-primary">Manage membership features</a>
<ul class="breadcrumb">
    <li><a href="{{ route('admin.dashboard') }}">Admin</a></li>
    <li><i class="fa-solid fa-chevron-right" style="font-size: 0.65rem;"></i></li>
    <li style="color: var(--text-heading); font-weight: 600;">Subscription Management</li>
</ul>

@php
$overviewMetrics = $overview['metrics'] ?? [];

$row1 = [
['label' => 'active subscriptions', 'key' => 'active_subscriptions'],
['label' => 'Monthly Recurring Revenue', 'key' => 'mrr'],
['label' => 'revenue Last 28 days', 'key' => 'revenue_28d'],
];

$row2 = [
['label' => 'new customers Last 28 days', 'key' => 'new_customers_28d'],
['label' => 'active users Last 28 days', 'key' => 'active_users_28d'],
];
@endphp

<div class="rc-analytics-container">
    <!-- Metric Cards Row 1 -->
    <div class="rc-metric-grid grid-3">
        @foreach($row1 as $card)
        @php $val = $overviewMetrics[$card['key']] ?? null; @endphp
        <div class="rc-card">
            <div class="rc-card-label">{{ $card['label'] }}</div>
            <div class="rc-card-value">
                @if($val === null || $val === '')
                --
                @elseif(in_array($card['key'], ['mrr', 'revenue_28d'], true) && is_numeric($val))
                ${{ number_format((float)$val, 2) }}
                @else
                {{ is_numeric($val) ? number_format((float)$val) : $val }}
                @endif
            </div>
        </div>
        @endforeach
    </div>

    <!-- Metric Cards Row 2 -->
    <div class="rc-metric-grid grid-2">
        @foreach($row2 as $card)
        @php $val = $overviewMetrics[$card['key']] ?? null; @endphp
        <div class="rc-card">
            <div class="rc-card-label">{{ $card['label'] }}</div>
            <div class="rc-card-value">
                @if($val === null || $val === '')
                --
                @else
                {{ is_numeric($val) ? number_format((float)$val) : $val }}
                @endif
            </div>
        </div>
        @endforeach
    </div>

    <!-- Customers Section -->
    <div class="rc-customers-section">
        <h2 class="rc-section-title">Customers</h2>

        <form method="GET" action="{{ route('admin.subscriptions.index') }}" class="rc-search-form">
            <input
                type="search"
                name="search"
                value="{{ $search }}"
                placeholder="Search name or email"
                aria-label="Search subscriptions by customer name or email">
            <button type="submit" class="rc-page-btn rc-page-btn--primary">Search</button>
            @if($search !== '')
            <a href="{{ route('admin.subscriptions.index') }}" class="rc-page-btn">Clear</a>
            @endif
        </form>

        <div class="table-responsive">
            <table class="rc-customers-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Tier</th>
                        <th>Revenue</th>
                        <th>Status</th>
                        <th>Store</th>
                        <th>Subscription Dates</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($subscriptions['items'] ?? [] as $customer)
                    <tr>
                        <td>
                            <div class="rc-customer-info">
                                <span class="rc-customer-name">{{ $customer['user_name'] ?? 'N/A' }}</span>
                                @if(!empty($customer['user_email']) && $customer['user_email'] !== 'N/A')
                                <span class="rc-customer-email">{{ $customer['user_email'] }}</span>
                                @endif
                            </div>
                        </td>
                        <td>
                            @php
                            $tierVal = $customer['tier'] ?? 'N/A';
                            $tierStyle = match(strtolower($tierVal)) {
                            'tier 2', 'tier2' => 'background:#ede9fe; color:#6d28d9;',
                            'tier 3', 'tier3' => 'background:#fef3c7; color:#b45309;',
                            default => 'background:#f1f5f9; color:#475569;',
                            };
                            @endphp
                            <span class="badge" style="{{ $tierStyle }} font-weight: 700;">{{ $tierVal }}</span>
                        </td>
                        <td>
                            <span style="font-weight: 600; color: var(--rc-text-primary);">${{ number_format((float)($customer['gross_revenue'] ?? 0), 2) }}</span>
                        </td>
                        <td>
                            <span class="badge {{ strtolower($customer['status'] ?? '') === 'active' ? 'badge-success' : 'badge-warning' }}">
                                {{ $customer['status'] ?? 'Expired' }}
                            </span>
                        </td>
                        <td>
                            @if(($customer['store'] ?? 'N/A') !== 'N/A')
                            <span class="badge" style="background: #f8fafc; border: 1px solid #e2e8f0; color: #475569;">{{ ucwords(str_replace('_', ' ', $customer['store'])) }}</span>
                            @else
                            <span style="color: var(--rc-text-muted);">N/A</span>
                            @endif
                        </td>
                        <td>
                            <div>Starts: {{ $customer['subscription_starts_at'] ?? 'N/A' }}</div>
                            <div>Ends: {{ $customer['subscription_ends_at'] ?? 'N/A' }}</div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 2rem;">No customers found.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="rc-pagination">
            {{-- Previous --}}
            @if($paginator->onFirstPage())
            <span class="rc-page-btn rc-page-btn--disabled">&laquo; Previous</span>
            @else
            <a href="{{ $paginator->previousPageUrl() }}" class="rc-page-btn">
                &laquo; Previous
            </a>
            @endif

            {{-- Page info --}}
            <div class="rc-page-info">
                <span class="rc-page-current">Page {{ $currentPage }} of {{ $totalPages }}</span>
                <span class="rc-page-total">{{ number_format($total) }} total records</span>
            </div>

            {{-- Next --}}
            @if($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" class="rc-page-btn rc-page-btn--primary">
                Next &raquo;
            </a>
            @else
            <span class="rc-page-btn rc-page-btn--disabled">Next &raquo;</span>
            @endif
        </div>
    </div>
</div>

<style>
    /* Design Tokens / Root Variables */
    :root {
        --rc-bg-card: #ffffff;
        --rc-bg-subtle: #f8fafc;
        --rc-border-color: #e2e8f0;
        --rc-text-primary: #0f172a;
        --rc-text-secondary: #475569;
        --rc-text-muted: #64748b;
        --rc-shadow-sm: 0 1px 3px 0 rgba(0, 0, 0, 0.05), 0 1px 2px -1px rgba(0, 0, 0, 0.05);
        --rc-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.05), 0 4px 6px -4px rgba(0, 0, 0, 0.05);
        --rc-radius: 12px;
        --rc-radius-inner: 8px;
        --rc-transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }

    /* Container Layout */
    .rc-analytics-container {
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
        margin-top: 1.25rem;
        font-family: inherit;
    }

    /* Metric Grid Layouts */
    .rc-metric-grid {
        display: grid;
        gap: 1.25rem;
    }

    .rc-metric-grid.grid-3 {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .rc-metric-grid.grid-2 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    /* Metric Cards */
    .rc-card {
        background-color: var(--rc-bg-card);
        border: 1px solid var(--rc-border-color);
        border-radius: var(--rc-radius);
        padding: 1.75rem 1.5rem;
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        /* Left-aligned for cleaner UI standard */
        justify-content: center;
        box-shadow: var(--rc-shadow-sm);
        transition: var(--rc-transition);
        position: relative;
        overflow: hidden;
    }

    .rc-card:hover {
        transform: translateY(-2px);
        box-shadow: var(--rc-shadow-hover);
        border-color: #cbd5e1;
    }

    .rc-card-label {
        font-size: 0.85rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--rc-text-muted);
        line-height: 1.2;
    }

    .rc-card-value {
        font-size: 1.75rem;
        font-weight: 700;
        color: var(--rc-text-primary);
        margin-top: 0.5rem;
        letter-spacing: -0.025em;
    }

    /* Customers Section */
    .rc-customers-section {
        margin-top: 1.5rem;
        background-color: var(--rc-bg-card);
        border: 1px solid var(--rc-border-color);
        border-radius: var(--rc-radius);
        padding: 1.5rem;
        box-shadow: var(--rc-shadow-sm);
    }

    .rc-section-title {
        font-size: 1.15rem;
        font-weight: 700;
        color: var(--rc-text-primary);
        margin-bottom: 1.25rem;
        padding-bottom: 0.75rem;
        border-bottom: 1px solid var(--rc-border-color);
    }

    .rc-search-form {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-bottom: 1rem;
    }

    .rc-search-form input {
        flex: 1 1 16rem;
        min-width: 0;
        padding: 0.5rem 0.75rem;
        border: 1px solid var(--rc-border-color);
        border-radius: var(--rc-radius-inner);
        color: var(--rc-text-primary);
        font: inherit;
    }

    .rc-search-form input:focus-visible {
        outline: 2px solid #0f172a;
        outline-offset: 2px;
    }

    /* Table Styles */
    .table-responsive {
        width: 100%;
        overflow-x: auto;
        margin-bottom: 1rem;
    }

    .rc-customers-table {
        width: 100%;
        border-collapse: collapse;
        text-align: left;
    }

    .rc-customers-table th {
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--rc-text-muted);
        text-transform: uppercase;
        padding: 1rem;
        border-bottom: 2px solid var(--rc-border-color);
    }

    .rc-customers-table td {
        padding: 1rem;
        border-bottom: 1px solid var(--rc-border-color);
        vertical-align: middle;
        font-size: 0.9rem;
        color: var(--rc-text-primary);
    }

    .rc-customers-table tr:hover {
        background-color: var(--rc-bg-subtle);
    }

    .rc-pagination {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 1.5rem;
        padding-top: 1rem;
        border-top: 1px solid var(--rc-border-color);
        gap: 1rem;
    }

    .rc-page-btn {
        display: inline-flex;
        align-items: center;
        padding: 0.5rem 1.125rem;
        font-size: 0.875rem;
        font-weight: 600;
        border-radius: var(--rc-radius-inner);
        text-decoration: none;
        border: 1px solid var(--rc-border-color);
        background: var(--rc-bg-subtle);
        color: var(--rc-text-primary);
        transition: var(--rc-transition);
        cursor: pointer;
    }

    .rc-page-btn:hover {
        background: #e2e8f0;
        border-color: #cbd5e1;
    }

    .rc-page-btn--primary {
        background: #0f172a;
        border-color: #0f172a;
        color: #ffffff;
    }

    .rc-page-btn--primary:hover {
        background: #1e293b;
        border-color: #1e293b;
    }

    .rc-page-btn--disabled {
        opacity: 0.4;
        cursor: not-allowed;
        pointer-events: none;
    }

    .rc-page-info {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.2rem;
    }

    .rc-page-current {
        font-size: 0.9rem;
        font-weight: 700;
        color: var(--rc-text-primary);
    }

    .rc-page-total {
        font-size: 0.775rem;
        color: var(--rc-text-muted);
        font-weight: 500;
    }

    .rc-customer-info {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }

    .rc-customer-name {
        font-size: 0.925rem;
        font-weight: 600;
        color: var(--rc-text-primary);
    }

    .rc-customer-email {
        font-size: 0.825rem;
        color: var(--rc-text-muted);
    }

    /* Badges */
    .badge {
        display: inline-flex;
        align-items: center;
        padding: 0.25rem 0.625rem;
        font-size: 0.75rem;
        font-weight: 600;
        border-radius: 9999px;
        text-transform: capitalize;
    }

    .badge-success {
        background-color: #dcfce7;
        color: #15803d;
    }

    .badge-warning {
        background-color: #fef9c3;
        color: #a16207;
    }

    /* Responsive Breakpoints */
    @media (max-width: 900px) {

        .rc-metric-grid.grid-3,
        .rc-metric-grid.grid-2 {
            grid-template-columns: repeat(1, minmax(0, 1fr));
        }

        .rc-customer-row {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.5rem;
        }

        .rc-customer-info {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.25rem;
        }
    }
</style>

<script>
    (() => {
        const form = document.querySelector('.rc-search-form');
        const input = form?.querySelector('input[name="search"]');
        const focusKey = 'admin-subscriptions-search-focus';

        if (!form || !input) {
            return;
        }

        if (sessionStorage.getItem(focusKey) === '1') {
            sessionStorage.removeItem(focusKey);
            input.focus();
            input.setSelectionRange(input.value.length, input.value.length);
        }

        let debounceTimer;
        input.addEventListener('input', () => {
            window.clearTimeout(debounceTimer);
            debounceTimer = window.setTimeout(() => {
                const currentSearch = new URLSearchParams(window.location.search).get('search')?.trim() ?? '';
                if (input.value.trim() === currentSearch) {
                    return;
                }

                sessionStorage.setItem(focusKey, '1');
                form.requestSubmit();
            }, 500);
        });
    })();
</script>
@endsection
