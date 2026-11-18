<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_pulse_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->date('metric_date');
            $table->string('question_key');
            $table->unsignedInteger('response_count')->default(0);
            $table->decimal('average_score', 4, 2)->nullable();
            $table->string('cohort_key')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'metric_date', 'question_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_pulse_metrics');
    }
};
