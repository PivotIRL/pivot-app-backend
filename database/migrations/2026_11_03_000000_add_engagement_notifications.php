<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_active_at')->nullable()->index();
            $table->string('notification_timezone', 64)->default('UTC');
        });
        Schema::create('engagement_notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_key', 100);
            $table->timestamp('sent_at');
            $table->unique(['user_id', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engagement_notification_deliveries');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['last_active_at', 'notification_timezone']));
    }
};
