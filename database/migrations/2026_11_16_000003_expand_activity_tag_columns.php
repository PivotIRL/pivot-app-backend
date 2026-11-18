<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('activities', function (Blueprint $table) {
            $table->text('sensory_tags')->nullable()->change();
            $table->text('outcome_tag')->nullable()->change();
        });
    }
    public function down(): void {
        Schema::table('activities', function (Blueprint $table) {
            $table->string('sensory_tags')->nullable()->change();
            $table->string('outcome_tag')->nullable()->change();
        });
    }
};
