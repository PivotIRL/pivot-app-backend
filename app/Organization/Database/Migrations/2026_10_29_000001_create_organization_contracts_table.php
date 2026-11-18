<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->string('package_code')->default('STANDARD');
            $table->unsignedInteger('seat_limit')->default(0);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->jsonb('pricing_config')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->index(['status', 'ends_at']);
        });

        DB::table('organizations')
            ->whereNotNull('contract_start_at')
            ->whereNotNull('contract_end_at')
            ->orderBy('id')
            ->chunk(100, function ($organizations): void {
                foreach ($organizations as $organization) {
                    DB::table('organization_contracts')->insert([
                        'organization_id' => $organization->id,
                        'status' => $organization->status,
                        'package_code' => $organization->package_code,
                        'seat_limit' => $organization->seat_limit,
                        'starts_at' => $organization->contract_start_at,
                        'ends_at' => $organization->contract_end_at,
                        'pricing_config' => $organization->pricing_config,
                        'created_at' => $organization->created_at,
                        'updated_at' => $organization->updated_at,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_contracts');
    }
};
