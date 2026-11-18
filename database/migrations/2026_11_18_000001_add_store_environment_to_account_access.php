<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('account_access', function (Blueprint $table) {
            $table->string('store_environment', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('account_access', fn (Blueprint $table) => $table->dropColumn('store_environment'));
    }
};
