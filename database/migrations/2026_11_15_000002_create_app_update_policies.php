<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema, DB};
return new class extends Migration {
    public function up(): void {
        Schema::create('app_update_policies', function (Blueprint $t) {
            $t->string('platform', 10)->primary(); $t->boolean('enabled')->default(false);
            $t->string('minimum_version', 32)->default('0.0.0'); $t->string('latest_version', 32)->default('0.0.0');
            $t->text('message'); $t->text('store_url')->nullable(); $t->unsignedInteger('revision')->default(1); $t->timestamps();
        });
        foreach (['android', 'ios'] as $platform) DB::table('app_update_policies')->insert([
            'platform' => $platform, 'enabled' => false, 'minimum_version' => '0.0.0', 'latest_version' => '0.0.0',
            'message' => 'Please update to access the latest features, improvements and more.',
            'store_url' => $platform === 'android' ? 'https://play.google.com/store/apps/details?id=com.pivotirl.mobile' : null,
            'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    public function down(): void { Schema::dropIfExists('app_update_policies'); }
};
