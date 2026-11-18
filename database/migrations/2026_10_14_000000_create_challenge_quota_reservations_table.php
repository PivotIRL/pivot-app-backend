<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('challenge_quota_reservations', function (Blueprint $table) {
            $table->id();
            $table->string('reservation_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('challenge_id')->constrained('activities')->cascadeOnDelete();
            $table->string('allocation');
            $table->string('pack_transaction_id')->nullable();
            $table->string('idempotency_key');
            $table->string('request_fingerprint', 64);
            $table->date('day_key');
            $table->string('day_timezone')->default('UTC');
            $table->string('status')->default('reserved');
            $table->timestamp('expires_at');
            $table->string('completion_id')->nullable()->unique();
            $table->json('completion_response')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key'], 'challenge_quota_user_idempotency_unique');
            $table->index(['user_id', 'day_key', 'allocation', 'status'], 'challenge_quota_daily_status_index');
            $table->index(['user_id', 'pack_transaction_id', 'status'], 'challenge_quota_pack_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('challenge_quota_reservations');
    }
};
