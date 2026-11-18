<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('organization_type')->default('CORPORATE');
            $table->string('account_type')->nullable();
            $table->string('status')->default('pending');
            $table->string('package_code')->default('STANDARD');
            $table->unsignedInteger('seat_limit')->default(0);
            $table->timestamp('contract_start_at')->nullable();
            $table->timestamp('contract_end_at')->nullable();
            $table->string('timezone')->default('UTC');
            $table->string('country_code', 2)->nullable();
            $table->jsonb('pricing_config')->nullable();
            $table->jsonb('reporting_config')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index('organization_type');
            $table->index(['status', 'contract_end_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
