<?php
namespace App\Console\Commands;

use App\Services\FcmService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendGlobalAnnouncements extends Command {
    protected $signature = 'notifications:send-global';
    protected $description = 'Deliver pending admin announcements to registered Android and iOS devices';
    public function handle(FcmService $fcm): int {
        DB::table('announcement_deliveries')->where('status', 'pending')->orderBy('id')->chunkById(100, function ($rows) use ($fcm) {
            foreach ($rows as $row) {
                if (!DB::table('announcement_deliveries')->where('id', $row->id)->where('status', 'pending')->update(['status' => 'processing', 'updated_at' => now()])) continue;
                $message = DB::table('global_announcements')->find($row->announcement_id);
                $device = DB::table('announcement_devices')->find($row->device_id);
                $status = 'cancelled';
                if ($device && $message && !$message->withdrawn_at && now()->lt($message->expires_at)
                    && in_array($device->platform, [$message->platform, ...($message->platform === 'both' ? ['android', 'ios'] : [])])) {
                    try {
                        $ok = $fcm->deliver($device->token, $message->title, $message->body,
                            ['type' => 'GLOBAL_ANNOUNCEMENT', 'event_id' => 'announcement-'.$message->id, 'announcement_id' => (string) $message->id],
                            'pivot_announcements', 'announcement-'.$message->id);
                        $status = $ok ? 'accepted' : 'failed';
                    } catch (\Throwable $e) { report($e); $status = 'failed'; }
                }
                DB::table('announcement_deliveries')->where('id', $row->id)->update(['status' => $status, 'updated_at' => now()]);
            }
        });
        return self::SUCCESS;
    }
}
