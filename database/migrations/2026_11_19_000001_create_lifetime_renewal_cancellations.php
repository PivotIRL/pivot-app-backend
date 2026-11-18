<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('lifetime_renewal_cancellations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('checkout_key')->unique();
            $table->string('environment', 20);
            $table->string('target_package', 20);
            $table->string('source_product');
            $table->string('source_base_plan')->nullable();
            $table->string('source_order');
            $table->string('token_hash', 64)->index();
            $table->text('encrypted_token')->nullable();
            $table->string('status', 30)->default('awaiting_purchase')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('consented_at');
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('lifetime_renewal_cancellations'); }
};
