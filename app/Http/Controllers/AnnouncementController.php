<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnnouncementController extends Controller {
    public function register(Request $request) {
        $data = $request->validate(['token' => 'required|string|max:4096', 'platform' => 'required|in:android,ios']);
        DB::table('announcement_devices')->upsert([['token_hash' => hash('sha256', $data['token']),
            'token' => $data['token'], 'platform' => $data['platform'], 'user_id' => $request->user()->id,
            'created_at' => now(), 'updated_at' => now()]], ['token_hash'], ['token', 'platform', 'user_id', 'updated_at']);
        return response()->noContent();
    }
    private function visible(Request $request, string $platform) {
        return DB::table('global_announcements')->where('in_app', true)->whereNull('withdrawn_at')
            ->where('expires_at', '>', now())->whereIn('platform', [$platform, 'both']);
    }
    public function index(Request $request) {
        $platform = $request->validate(['platform' => 'required|in:android,ios'])['platform'];
        $rows = $this->visible($request, $platform)->whereNotIn('id',
            DB::table('announcement_reads')->select('announcement_id')->where('user_id', $request->user()->id)->where('platform', $platform))
            ->orderBy('id')->limit(50)->get(['id', 'title', 'body']);
        return response()->json(['data' => $rows]);
    }
    public function read(Request $request, int $id) {
        $platform = $request->validate(['platform' => 'required|in:android,ios'])['platform'];
        abort_unless($this->visible($request, $platform)->where('id', $id)->exists(), 404);
        DB::table('announcement_reads')->insertOrIgnore(['announcement_id' => $id, 'user_id' => $request->user()->id,
            'platform' => $platform, 'read_at' => now()]);
        return response()->noContent();
    }
}
