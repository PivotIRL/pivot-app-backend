<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('announcement_devices', function (Blueprint $t) {
            $t->id(); $t->string('token_hash', 64)->unique(); $t->text('token');
            $t->string('platform', 10)->index(); $t->unsignedBigInteger('user_id')->index(); $t->timestamps();
        });
        Schema::create('global_announcements', function (Blueprint $t) {
            $t->id(); $t->uuid('request_key')->unique(); $t->string('title', 100); $t->text('body');
            $t->string('platform', 10); $t->boolean('in_app'); $t->boolean('push');
            $t->timestamp('expires_at'); $t->timestamp('withdrawn_at')->nullable();
            $t->unsignedBigInteger('created_by'); $t->timestamps();
            $t->index(['platform', 'expires_at']);
        });
        Schema::create('announcement_deliveries', function (Blueprint $t) {
            $t->id(); $t->foreignId('announcement_id')->constrained('global_announcements')->cascadeOnDelete();
            $t->foreignId('device_id')->constrained('announcement_devices')->cascadeOnDelete();
            $t->string('status', 20)->default('pending')->index(); $t->timestamps();
            $t->unique(['announcement_id', 'device_id']);
        });
        Schema::create('announcement_reads', function (Blueprint $t) {
            $t->id(); $t->foreignId('announcement_id')->constrained('global_announcements')->cascadeOnDelete();
            $t->unsignedBigInteger('user_id'); $t->string('platform', 10); $t->timestamp('read_at');
            $t->unique(['announcement_id', 'user_id', 'platform']);
            $t->index(['user_id', 'platform']);
        });
    }
    public function down(): void {
        foreach (['announcement_reads', 'announcement_deliveries', 'global_announcements', 'announcement_devices'] as $table) Schema::dropIfExists($table);
    }
};
