<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_identity_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained('organization_identity_providers')->cascadeOnDelete();
            $table->string('external_subject');
            $table->string('external_email')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->unique(['provider_id', 'external_subject']);
            $table->index('user_id');
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_identity_links');
    }
};
