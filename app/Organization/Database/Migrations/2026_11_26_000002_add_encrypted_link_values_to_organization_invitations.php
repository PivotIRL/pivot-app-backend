<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Encrypted copies, so the invitation list can show the link again. Lookups still use the hashes.
    public function up(): void
    {
        Schema::table('organization_invitations', function (Blueprint $table) {
            $table->text('token_encrypted')->nullable();
            $table->text('code_encrypted')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organization_invitations', function (Blueprint $table) {
            $table->dropColumn(['token_encrypted', 'code_encrypted']);
        });
    }
};
