<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_daily_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->date('metric_date');
            $table->unsignedInteger('eligible_members')->default(0);
            $table->unsignedInteger('active_members')->default(0);
            $table->unsignedInteger('access_attempts')->default(0);
            $table->unsignedInteger('challenge_acceptances')->default(0);
            $table->unsignedBigInteger('focus_minutes')->default(0);
            $table->unsignedBigInteger('reclaimed_minutes')->default(0);
            $table->unsignedInteger('monotasking_blocks')->default(0);
            $table->unsignedInteger('context_switches_prevented')->default(0);
            $table->unsignedInteger('active_streak_users')->default(0);
            $table->unsignedInteger('mood_response_count')->default(0);
            $table->decimal('average_mood_score', 4, 2)->nullable();
            $table->unsignedInteger('onboarding_completed')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'metric_date']);
            $table->index('metric_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_daily_metrics');
    }
};
