<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('domain')->unique();
            $table->string('status')->default('pending');
            $table->boolean('is_primary')->default(false);
            $table->string('verification_method')->nullable();
            $table->boolean('include_subdomains')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_domains');
    }
};
