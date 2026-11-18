<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('feature_key');
            $table->jsonb('value_json')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'feature_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_entitlements');
    }
};
