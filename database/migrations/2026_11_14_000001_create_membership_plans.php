<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('membership_plans', function (Blueprint $table) {
            $table->string('key', 20)->primary();
            $table->unsignedInteger('revision')->default(1);
            $table->json('limits');
            $table->timestamps();
        });
        $common = ['daily_challenges' => 3, 'category_count' => 5, 'blocked_apps' => 10,
            'scheduled_blocking_hours' => 12, 'blocking_intervals' => 8, 'blocked_app_usage_minutes' => 15,
            'hardcore' => false, 'team_connections' => 5, 'full_research' => false];
        foreach (['standard' => $common, 'premium' => array_replace($common, [
            'daily_challenges' => 5, 'category_count' => null, 'blocked_apps' => null,
            'scheduled_blocking_hours' => 24, 'blocking_intervals' => null, 'blocked_app_usage_minutes' => 30,
            'hardcore' => true, 'team_connections' => 10, 'full_research' => true,
        ])] as $key => $limits) {
            DB::table('membership_plans')->insert(['key' => $key, 'revision' => 1,
                'limits' => json_encode($limits, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void { Schema::dropIfExists('membership_plans'); }
};
