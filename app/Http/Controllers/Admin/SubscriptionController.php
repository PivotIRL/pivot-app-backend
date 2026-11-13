<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\RevenueCatService;
use App\Models\User;
use Illuminate\Http\Request;
use Carbon\Carbon;

class SubscriptionController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request, RevenueCatService $revenueCat)
    {
        $overview = $revenueCat->getMetricsOverview();
        $search = trim((string) $request->query('search', ''));

        $query = User::whereHas('latestSubscription')
            ->with('latestSubscription.tier')
            ->join('subscriptions', 'users.id', '=', 'subscriptions.user_id')
            ->orderByRaw('CASE WHEN subscriptions.started_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('subscriptions.started_at', 'desc')
            ->orderBy('subscriptions.created_at', 'desc')
            ->select('users.*');

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('users.name', 'like', "%{$search}%")
                    ->orWhere('users.email', 'like', "%{$search}%");
            });
        }

        $paginator = $query
            ->paginate(self::PER_PAGE, ['*'], 'page', (int) $request->query('page', 1))
            ->withQueryString();

        $currentPage = $paginator->currentPage();
        $totalPages  = $paginator->lastPage();
        $total       = $paginator->total();

        $realCustomers = collect($paginator->items())->map(function (User $user) use ($revenueCat) {
            $customerId = (string) $user->id;

            $subData       = $revenueCat->getCustomerSubscriptions($customerId);
            $subscriptions = $subData['items'] ?? [];
            $primarySub    = $subscriptions[0] ?? [];

            // Determine status
            $status = 'Expired';
            if (!empty($primarySub['expires_at'])) {
                if (Carbon::parse($primarySub['expires_at'])->isFuture()) {
                    $status = 'Active';
                }
            } elseif (($primarySub['status'] ?? '') === 'active') {
                $status = 'Active';
            }

            // Tier — read directly from local DB (authoritative source)
            $tier = $user->latestSubscription?->tier?->name ?? 'N/A';
            $startsAt = !empty($primarySub['starts_at'])
                ? $primarySub['starts_at']
                : $user->latestSubscription?->started_at;
            $endsAt = !empty($primarySub['ends_at'])
                ? $primarySub['ends_at']
                : $user->latestSubscription?->expires_at;

            return [
                'user_name'              => $user->name,
                'user_email'             => $user->email ?? 'N/A',
                'status'                 => $status,
                'tier'                   => $tier,
                'store'                  => $primarySub['store'] ?? 'N/A',
                'subscription_id'        => $primarySub['id'] ?? 'N/A',
                'auto_renewal_status'    => $primarySub['auto_renewal_status'] ?? 'N/A',
                'gross_revenue'          => $primarySub['total_revenue_in_usd']['gross'] ?? 0,
                'subscription_starts_at' => !empty($startsAt)
                    ? (is_numeric($startsAt)
                        ? date('M d, Y', $startsAt / 1000)
                        : Carbon::parse($startsAt)->format('M d, Y'))
                    : 'N/A',
                'subscription_ends_at'   => !empty($endsAt)
                    ? (is_numeric($endsAt)
                        ? date('M d, Y', $endsAt / 1000)
                        : Carbon::parse($endsAt)->format('M d, Y'))
                    : 'N/A',
            ];
        })->values();

        return view('admin.subscriptions.index', [
            'overview'      => $overview,
            'subscriptions' => ['items' => $realCustomers->all()],
            'currentPage'   => $currentPage,
            'totalPages'    => $totalPages,
            'total'         => $total,
            'paginator'     => $paginator,
            'search'        => $search,
        ]);
    }
}
