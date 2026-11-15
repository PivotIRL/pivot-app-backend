<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppUpdateController extends Controller {
    private const VERSION = '/^(0|[1-9][0-9]{0,5})\.(0|[1-9][0-9]{0,5})\.(0|[1-9][0-9]{0,5})$/';
    public function show(Request $request) {
        $platform = $request->validate(['platform' => 'required|in:android,ios'])['platform'];
        $row = DB::table('app_update_policies')->where('platform', $platform)->first();
        abort_unless($row, 503);
        return response()->json(['schema_version' => 1, 'platform' => $platform, 'revision' => (int) $row->revision,
            'enabled' => (bool) $row->enabled, 'minimum_version' => $row->minimum_version,
            'latest_version' => $row->latest_version, 'message' => $row->message, 'store_url' => $row->store_url])
            ->header('Cache-Control', 'no-store');
    }
    public function index() { return view('admin.app-updates.index', ['policies' => DB::table('app_update_policies')->orderBy('platform')->get()]); }
    public function update(Request $request, string $platform) {
        abort_unless(in_array($platform, ['android', 'ios'], true), 404);
        $data = $request->validate(['enabled' => 'required|boolean', 'revision' => 'required|integer|min:1',
            'minimum_version' => ['required', 'regex:'.self::VERSION], 'latest_version' => ['required', 'regex:'.self::VERSION],
            'message' => 'required|string|max:1000', 'store_url' => 'nullable|url:https|max:2048']);
        if (version_compare($data['latest_version'], $data['minimum_version'], '<')) throw ValidationException::withMessages(['latest_version' => 'Latest version must be at least the minimum supported version.']);
        $url = $data['store_url'] ?? null;
        $expectedHost = $platform === 'android' ? 'play.google.com' : 'apps.apple.com';
        if (($data['enabled'] && !$url) || ($url && strtolower((string) parse_url($url, PHP_URL_HOST)) !== $expectedHost))
            throw ValidationException::withMessages(['store_url' => 'Enter the official app store URL before enabling required updates.']);
        $revision = (int) $data['revision']; unset($data['revision']);
        $updated = DB::table('app_update_policies')->where('platform', $platform)->where('revision', $revision)
            ->update([...$data, 'revision' => $revision + 1, 'updated_at' => now()]);
        if (!$updated) throw ValidationException::withMessages(['revision' => 'This policy changed in another session. Reload before saving.']);
        return redirect()->route('admin.app-updates.index')->with('success', ucfirst($platform).' update policy saved.');
    }
}
