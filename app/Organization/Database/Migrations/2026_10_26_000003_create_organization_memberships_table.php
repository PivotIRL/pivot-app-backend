<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('invited');
            $table->string('joined_via')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('deactivation_reason')->nullable();
            $table->unsignedBigInteger('provisioning_source_id')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        // Phase 2 V1: one active corporate organization per user.
        DB::statement("CREATE UNIQUE INDEX organization_memberships_one_active_per_user ON organization_memberships (user_id) WHERE status = 'active'");
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_memberships');
    }
};
