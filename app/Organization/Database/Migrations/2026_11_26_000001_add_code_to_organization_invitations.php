<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Short code sent with each invitation. Nullable: invitations created earlier have only a token.
    public function up(): void
    {
        Schema::table('organization_invitations', function (Blueprint $table) {
            $table->string('code_hash', 64)->nullable();
            $table->unsignedSmallInteger('code_failed_attempts')->default(0);
            $table->index(['email', 'code_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('organization_invitations', function (Blueprint $table) {
            $table->dropIndex(['email', 'code_hash']);
            $table->dropColumn(['code_hash', 'code_failed_attempts']);
        });
    }
};
