<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AnnouncementController extends Controller {
    public function index() {
        $messages = DB::table('global_announcements')->orderByDesc('id')->paginate(20);
        $counts = DB::table('announcement_deliveries')->whereIn('announcement_id', $messages->pluck('id'))
            ->selectRaw('announcement_id, status, count(*) as total')->groupBy('announcement_id', 'status')->get()->groupBy('announcement_id');
        return view('admin.announcements.index', compact('messages', 'counts'));
    }
    public function store(Request $request) {
        $data = $request->validate(['request_key' => 'required|uuid', 'title' => 'required|string|max:100',
            'body' => 'required|string|max:1000', 'platform' => 'required|in:android,ios,both',
            'in_app' => 'required|boolean', 'push' => 'required|boolean', 'days' => 'required|integer|min:1|max:90']);
        if (!$data['in_app'] && !$data['push']) throw ValidationException::withMessages(['in_app' => 'Select in-app, push, or both.']);
        if (strlen($data['title']) + strlen($data['body']) > 3000) throw ValidationException::withMessages(['body' => 'Shorten this message to fit the push notification payload.']);
        DB::transaction(function () use ($data, $request) {
            $inserted = DB::table('global_announcements')->insertOrIgnore([
                'request_key' => $data['request_key'], 'title' => $data['title'], 'body' => $data['body'],
                'platform' => $data['platform'], 'in_app' => $data['in_app'], 'push' => $data['push'],
                'expires_at' => now()->addDays((int) $data['days']), 'created_by' => $request->user('admin')->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if (!$inserted || !$data['push']) return;
            $id = DB::table('global_announcements')->where('request_key', $data['request_key'])->value('id');
            DB::table('announcement_devices')->when($data['platform'] !== 'both', fn ($q) => $q->where('platform', $data['platform']))
                ->orderBy('id')->chunkById(500, function ($devices) use ($id) {
                    DB::table('announcement_deliveries')->insertOrIgnore($devices->map(fn ($d) => [
                        'announcement_id' => $id, 'device_id' => $d->id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                    ])->all());
                });
        });
        return redirect()->route('admin.announcements.index')->with('success', $data['push']
            ? 'Notification published. Push delivery is queued for registered devices.'
            : 'Notification published. Members can now see it in the app.');
    }
    public function withdraw(int $id) {
        DB::transaction(function () use ($id) {
            DB::table('global_announcements')->where('id', $id)->update(['withdrawn_at' => now(), 'updated_at' => now()]);
            DB::table('announcement_deliveries')->where('announcement_id', $id)->where('status', 'pending')->update(['status' => 'cancelled', 'updated_at' => now()]);
        });
        return back()->with('success', 'Announcement withdrawn. Pushes already sent cannot be recalled.');
    }
}
