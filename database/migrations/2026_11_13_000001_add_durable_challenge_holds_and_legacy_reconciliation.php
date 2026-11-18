<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('challenge_quota_reservations', function (Blueprint $table) {
            // Older expired holds must be revalidated before they can become durable.
            $table->boolean('durable_hold')->default(false);
        });
        Schema::create('legacy_pack_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('transaction_id');
            $table->string('product_id');
            $table->unsignedInteger('local_total');
            $table->unsignedInteger('local_remaining');
            $table->unsignedInteger('server_remaining')->nullable();
            $table->string('resolution');
            $table->timestamps();
            $table->unique(['user_id', 'transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_pack_reconciliations');
        Schema::table('challenge_quota_reservations', fn (Blueprint $table) => $table->dropColumn('durable_hold'));
    }
};
