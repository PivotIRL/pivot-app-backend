<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A pending (unverified) claim must not block the real owner of a domain.
     * - Each organization can claim a domain once.
     * - Only one organization can hold the VERIFIED domain.
     */
    public function up(): void
    {
        Schema::table('organization_domains', function (Blueprint $table) {
            $table->dropUnique(['domain']);
            $table->unique(['organization_id', 'domain']);
            $table->index('domain');
        });

        DB::statement(
            "CREATE UNIQUE INDEX organization_domains_verified_domain_unique
             ON organization_domains (domain)
             WHERE status = 'verified'"
        );
    }

    // Fails if several organizations hold pending claims for the same domain; remove those first.
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS organization_domains_verified_domain_unique');

        Schema::table('organization_domains', function (Blueprint $table) {
            $table->dropIndex(['domain']);
            $table->dropUnique(['organization_id', 'domain']);
            $table->unique('domain');
        });
    }
};
