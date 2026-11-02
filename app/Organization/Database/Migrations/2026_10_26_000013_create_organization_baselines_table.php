<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_baselines', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->date('baseline_start');
            $table->date('baseline_end');
            $table->unsignedInteger('active_users')->default(0);
            $table->decimal('avg_focus_minutes', 10, 2)->default(0);
            $table->decimal('avg_access_attempts', 10, 2)->default(0);
            $table->decimal('avg_reclaimed_minutes', 10, 2)->default(0);
            $table->decimal('avg_mood_score', 4, 2)->nullable();
            $table->string('source');
            $table->timestamp('locked_at')->nullable();
            $table->index(['organization_id', 'baseline_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_baselines');
    }
};
