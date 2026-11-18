<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('account_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('selected_package')->nullable();
            $table->string('effective_package')->nullable();
            $table->string('payment_path')->nullable();
            $table->string('access_status')->default('none');
            $table->string('access_source')->nullable();
            $table->string('product_id')->nullable();
            $table->string('base_plan_id')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('access_ends_at')->nullable();
            $table->boolean('legacy_all_categories')->default(false);
            $table->boolean('legacy_announcement_eligible')->default(false);
            $table->unsignedInteger('policy_version')->default(1);
            $table->timestamps();
        });

        Schema::create('membership_trials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status')->default('eligible');
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('reservation_expires_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('source')->nullable();
            $table->string('transaction_id')->nullable();
            $table->timestamps();
        });

        Schema::create('membership_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('attempt_id')->unique();
            $table->string('operation');
            $table->string('idempotency_key');
            $table->string('request_fingerprint', 64);
            $table->json('quote');
            $table->string('status')->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->string('verified_transaction_id')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'operation', 'idempotency_key'], 'membership_attempts_idempotency_unique');
            $table->index(['user_id', 'status']);
        });

        Schema::create('verified_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('store')->nullable();
            $table->string('environment')->nullable();
            $table->string('transaction_id');
            $table->string('original_transaction_id')->nullable()->index();
            $table->string('product_id');
            $table->string('base_plan_id')->nullable();
            $table->string('entitlement')->nullable();
            $table->string('package')->nullable();
            $table->string('payment_path')->nullable();
            $table->string('renewal_status')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->unique(['store', 'environment', 'transaction_id'], 'verified_purchase_identity_unique');
        });

        Schema::create('membership_announcement_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('version');
            $table->timestamp('acknowledged_at');
            $table->timestamps();
            $table->unique(['user_id', 'version'], 'membership_announcement_user_version_unique');
        });

        Schema::create('membership_categories', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hobby_id')->constrained('hobbies')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['user_id', 'hobby_id']);
        });

        // This table may already exist in installations that created the
        // RevenueCat webhook store before this consolidated migration.
        if (!Schema::hasTable('revenuecat_webhook_events')) {
            Schema::create('revenuecat_webhook_events', function (Blueprint $table) {
                $table->id();
                $table->string('event_id')->unique();
                $table->string('status')->default('received');
                $table->json('payload');
                $table->timestamp('processed_at')->nullable();
                $table->text('error')->nullable();
                $table->timestamps();
            });
        }

        // Snapshot the current free cohort once; later registrations are not
        // automatically enrolled in the legacy migration announcement.
        $legacyFreeIds = DB::table('users')
            ->leftJoin('subscriptions', 'subscriptions.user_id', '=', 'users.id')
            ->where(function ($query) {
                $query->whereNull('subscriptions.id')
                    ->orWhere(function ($query) {
                        $query->where('subscriptions.tier_id', 1)->whereNull('subscriptions.product_id');
                    });
            })->pluck('users.id');
        foreach ($legacyFreeIds as $userId) {
            DB::table('account_access')->insertOrIgnore([
                'user_id' => $userId,
                'access_status' => 'none',
                'legacy_announcement_eligible' => true,
                'policy_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

    }

    public function down(): void
    {
        Schema::dropIfExists('revenuecat_webhook_events');
        Schema::dropIfExists('membership_categories');
        Schema::dropIfExists('membership_announcement_acknowledgements');
        Schema::dropIfExists('verified_purchases');
        Schema::dropIfExists('membership_attempts');
        Schema::dropIfExists('membership_trials');
        Schema::dropIfExists('account_access');
    }
};
