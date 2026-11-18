<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Global Pivot staff role (PIVOT_SUPER_ADMIN / PIVOT_SUPPORT). Null for every non-staff user.
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pivot_role')->nullable();
            $table->index('pivot_role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['pivot_role']);
            $table->dropColumn('pivot_role');
        });
    }
};
