<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_identity_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('provider_type');
            $table->string('issuer_url')->nullable();
            $table->string('client_id')->nullable();
            $table->text('encrypted_client_secret')->nullable();
            $table->string('metadata_url')->nullable();
            $table->string('tenant_id')->nullable();
            $table->boolean('is_enforced')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['organization_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_identity_providers');
    }
};
