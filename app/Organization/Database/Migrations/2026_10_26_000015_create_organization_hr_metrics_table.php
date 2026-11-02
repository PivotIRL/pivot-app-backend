<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_hr_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('metric_key');
            $table->decimal('metric_value', 14, 4);
            $table->string('cohort_key')->nullable();
            $table->string('source_system');
            $table->timestamp('imported_at')->useCurrent();
            $table->index(['organization_id', 'metric_key', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_hr_metrics');
    }
};
