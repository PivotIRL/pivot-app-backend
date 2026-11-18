<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // A user can hold each organization role at most once while it is not revoked.
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX organization_role_assignments_active_unique
             ON organization_role_assignments (organization_id, user_id, role)
             WHERE revoked_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS organization_role_assignments_active_unique');
    }
};
