<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // At most one pending invitation per email in an organization. Re-inviting replaces the old one.
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX organization_invitations_one_pending_per_email ON organization_invitations (organization_id, email) WHERE status = 'pending'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS organization_invitations_one_pending_per_email');
    }
};
