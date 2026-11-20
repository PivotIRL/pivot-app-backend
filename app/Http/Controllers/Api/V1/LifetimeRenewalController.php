<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Services\Membership\LifetimeRenewalCancellation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class LifetimeRenewalController extends Controller
{
    public function prepare(Request $request, LifetimeRenewalCancellation $service) {
        $data = $request->validate(['checkout_key' => 'required|uuid', 'selected_package' => 'required|in:standard,premium',
            'purchase_token' => 'required|string|max:4096', 'consent' => 'required|accepted']);
        try { $service->prepare($request->user(), $data['checkout_key'], $data['selected_package'], $data['purchase_token']); }
        catch (\RuntimeException $e) { return response()->json(['message' => $e->getMessage()], 409); }
        return response()->noContent();
    }
    public function status(Request $request) {
        $rows = DB::table('lifetime_renewal_cancellations as c')->join('membership_attempts as a', function ($join) {
            $join->on('c.checkout_key', '=', 'a.idempotency_key')->on('c.user_id', '=', 'a.user_id');
        })->where('c.user_id', $request->user()->id)->where('a.operation', 'change')->where('a.status', 'completed')
            ->orderByDesc('c.id')->get(['c.id', 'c.status']);
        return response()->json(['pending' => $rows->contains(fn ($r) => $r->status !== 'completed'),
            'completed' => $rows->isNotEmpty() && $rows->every(fn ($r) => $r->status === 'completed')]);
    }
}
