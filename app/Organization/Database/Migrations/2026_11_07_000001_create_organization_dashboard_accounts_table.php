<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dashboard credentials only. Roles and memberships stay on users.id; users.password is never touched.
        Schema::create('organization_dashboard_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('email')->unique();
            $table->string('password')->nullable(); // Null until first-time setup.
            $table->timestamp('password_set_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_dashboard_accounts');
    }
};
