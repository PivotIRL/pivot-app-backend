<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Membership\MembershipPlanCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MembershipPlanController extends Controller
{
    public function index(MembershipPlanCatalog $catalog)
    {
        return view('admin.membership-plans.index', ['plans' => $catalog->plans()]);
    }

    public function update(Request $request, string $plan)
    {
        abort_unless(in_array($plan, ['standard', 'premium'], true), 404);
        $rules = ['revision' => 'required|integer|min:1'];
        foreach (['daily_challenges', 'team_connections'] as $field) $rules[$field] = 'required|integer|min:0|max:10000';
        foreach (['category_count', 'blocked_apps', 'blocking_intervals'] as $field) $rules[$field] = 'present|nullable|integer|min:1|max:10000';
        $rules['scheduled_blocking_hours'] = 'required|integer|min:0|max:24';
        $rules['blocked_app_usage_minutes'] = 'required|integer|min:0|max:1440';
        $rules['hardcore'] = $rules['full_research'] = 'required|boolean';
        $data = $request->validate($rules);
        $revision = (int) $data['revision'];
        unset($data['revision']);
        foreach ($data as $field => $value) {
            $data[$field] = $value === null ? null : (in_array($field, ['hardcore', 'full_research']) ? (bool) $value : (int) $value);
        }
        $updated = DB::table('membership_plans')->where('key', $plan)->where('revision', $revision)->update([
            'limits' => json_encode($data, JSON_THROW_ON_ERROR), 'revision' => $revision + 1, 'updated_at' => now(),
        ]);
        if (! $updated) throw ValidationException::withMessages(['revision' => 'This plan changed in another session. Reload before saving.']);
        return redirect()->route('admin.membership-plans.index')->with('success', ucfirst($plan).' membership updated.');
    }
}
