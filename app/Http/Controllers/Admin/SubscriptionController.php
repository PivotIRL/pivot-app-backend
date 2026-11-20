<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Membership\{MembershipAccessService, MembershipEnvironment};
use App\Services\RevenueCatService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubscriptionController extends Controller
{
    public function index(Request $request, RevenueCatService $revenueCat, MembershipAccessService $access, MembershipEnvironment $environments)
    {
        $overview = $revenueCat->getMetricsOverview();
        $search = trim((string) $request->query('search', ''));
        // EXISTS keeps one customer per row, including members without legacy subscriptions.
        $query = User::query()->with('subscription')->where(function ($q) {
            $q->whereHas('subscription')->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('account_access')
                ->whereColumn('account_access.user_id', 'users.id'));
        });
        if ($search !== '') {
            $query->where(fn ($q) => $q->where('users.name', 'like', "%{$search}%")->orWhere('users.email', 'like', "%{$search}%"));
        }
        $paginator = $query->orderByDesc('users.id')->paginate(15)->withQueryString();
        $customers = collect($paginator->items())->map(function (User $user) use ($access, $environments) {
            $membership = $access->snapshot($user);
            $environment = $environments->forUser($user);
            $active = in_array($membership['access_status'], ['active', 'trial'], true);
            $package = $active ? $membership['effective_package'] : $membership['selected_package'];
            $product = explode(':', $membership['product_id'] ?? '');
            $receipt = $membership['access_source'] === 'store' ? DB::table('verified_purchases')
                ->where('user_id', $user->id)->where('environment', $environment)->where('product_id', $product[0])
                ->when(isset($product[1]), fn ($q) => $q->where('base_plan_id', $product[1]))
                ->where('payment_path', $membership['payment_path'])->whereNull('revoked_at')
                ->orderByDesc('updated_at')->orderByDesc('id')->first() : null;
            $payload = $receipt ? (json_decode($receipt->payload ?? '{}', true) ?: []) : [];
            $start = $payload['current_period_starts_at'] ?? $payload['purchased_at'] ?? $payload['purchase_date'] ?? null;
            if ($membership['access_source'] === 'legacy') $start = $user->subscription?->started_at;
            $end = $membership['access_status'] === 'trial' ? $membership['trial_ends_at'] : $membership['access_ends_at'];
            return [
                'user_name' => $user->name, 'user_email' => $user->email,
                'tier' => $package ? ucfirst($package).' '.ucfirst($membership['payment_path'] ?? '') : 'No membership',
                'status' => ucfirst(str_replace('_', ' ', $membership['access_status'])),
                'environment' => ucfirst($environment),
                'source' => ucfirst(str_replace('_', ' ', $membership['access_source'] ?? 'unknown')),
                'store' => $receipt?->store ?? 'N/A', 'auto_renewal_status' => $receipt?->renewal_status,
                // Unknown revenue must not be presented as zero or borrowed from a different plan.
                'gross_revenue' => $payload['total_revenue_in_usd']['gross'] ?? null,
                'subscription_starts_at' => $this->dateLabel($start),
                'subscription_ends_at' => $end ? $this->dateLabel($end) : ($active && $membership['payment_path'] === 'lifetime' ? 'No expiry' : 'N/A'),
            ];
        });
        return view('admin.subscriptions.index', [
            'overview' => $overview, 'subscriptions' => ['items' => $customers->all()],
            'currentPage' => $paginator->currentPage(), 'totalPages' => $paginator->lastPage(),
            'total' => $paginator->total(), 'paginator' => $paginator, 'search' => $search,
        ]);
    }

    private function dateLabel(mixed $value): string
    {
        if (! $value) return 'N/A';
        try {
            return (is_numeric($value) ? Carbon::createFromTimestampMs($value) : Carbon::parse($value))->utc()->format('M d, Y H:i').' UTC';
        } catch (\Throwable) { return 'N/A'; }
    }
}
