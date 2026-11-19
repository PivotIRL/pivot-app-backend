<?php

namespace Tests\Feature\Organization;

use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationDomain;
use App\Organization\Models\OrganizationEntitlement;
use App\Organization\Models\OrganizationInvitation;
use App\Organization\Models\OrganizationMembership;
use App\Organization\Models\OrganizationRedemptionCode;
use App\Organization\Models\OrganizationRoleAssignment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Runs the UUID/BIGINT conversion both ways against real rows. Needs PostgreSQL (the whole module does).
 *
 * Transactions: RefreshDatabase wraps each test in one outer transaction. Every migration run below goes through
 * DB::transaction(), which inside that outer transaction becomes a SAVEPOINT. That mirrors the Migrator (one
 * transaction per migration, rolled back as a unit on any exception) closely enough to prove atomicity, without
 * committing DDL to the shared test database. The outer transaction rolls everything back at the end of each test.
 */
class OrganizationIdConversionTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'Database/Migrations/2026_11_19_000001_convert_organization_ids_to_bigint.php';

    private const MAP = 'organization_id_map';

    /** Tables filled by seedRelatedRows(). */
    private const SEEDED_TABLES = [
        'organization_domains', 'organization_entitlements', 'organization_redemption_codes', 'organization_invitations',
        'organization_memberships', 'organization_role_assignments', 'organization_contracts', 'organization_audit_logs',
    ];

    // ------------------------------------------------------------------------------------------------------------
    // Fresh schema
    // ------------------------------------------------------------------------------------------------------------

    public function test_schema_is_bigint_with_foreign_keys_after_migrations(): void
    {
        $this->assertSame('bigint', $this->idType());

        $fks = DB::scalar("SELECT COUNT(*) FROM pg_constraint WHERE contype = 'f' AND confrelid = 'organizations'::regclass AND convalidated");
        $this->assertSame(15, (int) $fks);

        foreach ([
            'organization_role_assignments_active_unique',
            'organization_domains_verified_domain_unique',
            'organization_invitations_one_pending_per_email',
            'organization_memberships_one_active_per_user',
        ] as $index) {
            $this->assertSame(1, (int) DB::scalar('SELECT COUNT(*) FROM pg_indexes WHERE indexname = ?', [$index]), $index);
        }

        $this->assertIdentityColumn();
    }

    public function test_snapshot_table_is_not_created(): void
    {
        $this->assertFalse(Schema::hasTable('organization_conversion_snapshot'));
    }

    public function test_new_organizations_get_ids_above_every_mapped_id(): void
    {
        $max = (int) DB::table(self::MAP)->max('new_id');
        $organization = Organization::factory()->create();

        $this->assertGreaterThan($max, $organization->id);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Round trip: data, relationships and schema objects survive both directions
    // ------------------------------------------------------------------------------------------------------------

    public function test_round_trip_preserves_rows_relationships_audit_references_and_schema_objects(): void
    {
        $organization = Organization::factory()->create();
        $id = $organization->id;
        $this->seedRelatedRows($organization);

        $shape = $this->shape();
        $counts = $this->counts();
        $rowsPerTable = $this->rowsFor($id);
        foreach (self::SEEDED_TABLES as $table) {
            $this->assertGreaterThanOrEqual(1, $rowsPerTable[$table], "{$table} must hold a row for the organization");
        }

        // Down: back to UUIDs.
        $this->migrate('down');

        $this->assertSame('uuid', $this->idType());
        $uuid = DB::table('organizations')->where('slug', $organization->slug)->value('id');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $uuid);
        $this->assertSame($rowsPerTable, $this->rowsFor($uuid), 'rows per table after down');

        $audit = DB::table('organization_audit_logs')->where('action', 'test.action')->first();
        $this->assertSame($uuid, $audit->target_id);
        $this->assertSame($uuid, json_decode($audit->metadata, true)['organization_id']);

        // Indexes (incl. partial), constraints (incl. FK delete actions) and nullability are unchanged by the round trip.
        $this->assertSame($counts, $this->counts());
        $this->assertSame($shape, $this->shape(), 'schema objects after down');

        // Up again: the same numeric ID comes back.
        $this->migrate('up');

        $this->assertSame('bigint', $this->idType());
        $this->assertSame($id, (int) DB::table('organizations')->where('slug', $organization->slug)->value('id'));

        $this->assertSame($rowsPerTable, $this->rowsFor($id), 'rows per table after up');

        $audit = DB::table('organization_audit_logs')->where('action', 'test.action')->first();
        $this->assertSame((string) $id, $audit->target_id);
        $this->assertSame($id, json_decode($audit->metadata, true)['organization_id']);
        $this->assertSame('keep', json_decode($audit->metadata, true)['note']);

        $this->assertSame($counts, $this->counts());
        $this->assertSame($shape, $this->shape(), 'schema objects after up');
        $this->assertIdentityColumn();
        $this->assertGreaterThan($id, Organization::factory()->create()->id);
    }

    public function test_rollback_keeps_organizations_created_after_conversion(): void
    {
        $created = Organization::factory()->create();
        $this->assertFalse(
            DB::table(self::MAP)->where('new_id', $created->id)->exists(),
            'A post-conversion organization has no map row yet.'
        );

        $this->migrate('down');

        $this->assertTrue(DB::table(self::MAP)->where('new_id', $created->id)->exists());
        $this->assertSame(1, DB::table('organizations')->where('slug', $created->slug)->count());

        $this->migrate('up');

        $this->assertSame($created->id, (int) DB::table('organizations')->where('slug', $created->slug)->value('id'));
    }

    public function test_identity_sequence_never_reuses_an_id_after_down_and_up(): void
    {
        $first = Organization::factory()->create();
        $this->migrate('down');
        $this->migrate('up');

        $highestIssued = (int) DB::table(self::MAP)->max('new_id');
        $next = Organization::factory()->create();

        $this->assertGreaterThan(max($first->id, $highestIssued), $next->id);
        $this->assertIdentityColumn();
    }

    // ------------------------------------------------------------------------------------------------------------
    // Audit metadata: case-insensitive detection
    // ------------------------------------------------------------------------------------------------------------

    public function test_uppercase_uuid_in_an_allowed_metadata_key_and_target_id_is_remapped(): void
    {
        $organization = Organization::factory()->create();
        $this->migrate('down');

        $uuid = DB::table('organizations')->where('slug', $organization->slug)->value('id');
        DB::table('organization_audit_logs')->insert([
            'actor_type' => 'system', 'action' => 'test.uppercase',
            'target_type' => 'organization', 'target_id' => strtoupper($uuid),
            'metadata' => json_encode(['organization_id' => strtoupper($uuid)]),
        ]);

        $this->migrate('up');

        $id = (int) DB::table('organizations')->where('slug', $organization->slug)->value('id');
        $row = DB::table('organization_audit_logs')->where('action', 'test.uppercase')->first();
        $this->assertSame((string) $id, $row->target_id);
        $this->assertSame($id, json_decode($row->metadata, true)['organization_id']);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Failed conversions leave the schema and data exactly as they were
    // ------------------------------------------------------------------------------------------------------------

    public function test_late_failure_after_the_columns_were_swapped_rolls_everything_back(): void
    {
        $organization = Organization::factory()->create();
        $this->seedRelatedRows($organization);
        $this->migrate('down');

        // The metadata check runs after the swap, the identity and every constraint have been rebuilt.
        DB::table('organization_audit_logs')->insert([
            'actor_type' => 'system', 'action' => 'test.unexpected',
            'metadata' => json_encode(['something_else' => DB::table('organizations')->value('id')]),
        ]);

        $this->assertFailureChangesNothing('up', 'unexpected audit metadata keys');
    }

    public function test_uppercase_uuid_in_an_unexpected_metadata_key_is_detected_and_rolled_back(): void
    {
        $organization = Organization::factory()->create();
        $this->migrate('down');

        DB::table('organization_audit_logs')->insert([
            'actor_type' => 'system', 'action' => 'test.unexpected-upper',
            'metadata' => json_encode(['note' => strtoupper(DB::table('organizations')->where('slug', $organization->slug)->value('id'))]),
        ]);

        $this->assertFailureChangesNothing('up', 'unexpected audit metadata keys');
    }

    public function test_down_failure_also_leaves_the_schema_unchanged(): void
    {
        Organization::factory()->create();
        DB::statement('ALTER TABLE organization_domains ADD CONSTRAINT organization_domains_org_check CHECK (organization_id > 0)');

        $this->assertFailureChangesNothing('down', 'CHECK constraint');
    }

    public function test_organization_id_column_without_a_foreign_key_aborts(): void
    {
        $this->migrate('down');
        DB::statement('CREATE TABLE organization_unlinked_refs (id bigserial PRIMARY KEY, organization_id uuid)');

        $this->assertFailureChangesNothing('up', 'organization_unlinked_refs.organization_id');
    }

    public function test_check_constraint_on_organization_id_aborts(): void
    {
        $this->migrate('down');
        DB::statement('ALTER TABLE organization_domains ADD CONSTRAINT organization_domains_org_check CHECK (organization_id IS NOT NULL)');

        $this->assertFailureChangesNothing('up', 'CHECK constraint organization_domains_org_check');
    }

    public function test_exclude_constraint_on_organization_id_aborts(): void
    {
        $this->migrate('down');
        DB::statement('ALTER TABLE organization_contracts ADD CONSTRAINT organization_contracts_org_exclude EXCLUDE USING btree (organization_id WITH =)');

        $this->assertFailureChangesNothing('up', 'EXCLUDE constraint organization_contracts_org_exclude');
    }

    public function test_default_on_organization_id_aborts(): void
    {
        $this->migrate('down');
        DB::statement("ALTER TABLE organization_contracts ALTER COLUMN organization_id SET DEFAULT '00000000-0000-0000-0000-000000000000'");

        $this->assertFailureChangesNothing('up', 'default, generated or identity definition');
    }

    public function test_trigger_on_an_organization_table_aborts(): void
    {
        $this->migrate('down');
        DB::statement('CREATE FUNCTION organization_conversion_test_noop() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN NEW; END $$');
        DB::statement('CREATE TRIGGER organization_conversion_test_trigger BEFORE INSERT ON organization_domains FOR EACH ROW EXECUTE FUNCTION organization_conversion_test_noop()');

        $this->assertFailureChangesNothing('up', 'trigger organization_conversion_test_trigger');
    }

    public function test_expression_index_on_organization_id_aborts(): void
    {
        $this->migrate('down');
        DB::statement('CREATE INDEX organization_domains_expression_idx ON organization_domains ((organization_id::text))');

        $this->assertFailureChangesNothing('up', 'outside their key columns');
    }

    public function test_partial_index_with_organization_id_in_its_predicate_aborts(): void
    {
        $this->migrate('down');
        DB::statement('CREATE INDEX organization_domains_predicate_idx ON organization_domains (domain) WHERE organization_id IS NOT NULL');

        $this->assertFailureChangesNothing('up', 'outside their key columns');
    }

    // ------------------------------------------------------------------------------------------------------------
    // Indexes and constraints on organizations.id itself
    // ------------------------------------------------------------------------------------------------------------

    public function test_extra_index_using_organizations_id_aborts(): void
    {
        $this->migrate('down');
        DB::statement('CREATE INDEX organizations_id_name_idx ON organizations (id, name)');

        $this->assertFailureChangesNothing('up', 'index organizations_id_name_idx on organizations.id');
    }

    public function test_unique_constraint_on_organizations_id_aborts(): void
    {
        $this->migrate('down');
        DB::statement('ALTER TABLE organizations ADD CONSTRAINT organizations_id_slug_unique UNIQUE (id, slug)');

        $this->assertFailureChangesNothing('up', 'unique constraint organizations_id_slug_unique on organizations.id');
    }

    public function test_composite_primary_key_involving_organizations_id_aborts(): void
    {
        $organization = Organization::factory()->create();
        $this->seedRelatedRows($organization);
        $this->migrate('down');
        $this->replaceOrganizationsPrimaryKeyWithComposite();

        $this->assertFailureChangesNothing('up', 'composite primary key organizations_composite_pkey');
    }

    public function test_composite_primary_key_aborts_the_down_direction_too(): void
    {
        Organization::factory()->create();
        $this->replaceOrganizationsPrimaryKeyWithComposite();

        $this->assertFailureChangesNothing('down', 'composite primary key organizations_composite_pkey');
    }

    public function test_primary_key_with_an_include_column_aborts(): void
    {
        $organization = Organization::factory()->create();
        $this->seedRelatedRows($organization);
        $this->migrate('down');
        $this->replaceOrganizationsPrimaryKeyWithIncludeColumn();

        // The key must really carry the INCLUDE column, otherwise this test would prove nothing.
        $this->assertSame(
            1,
            (int) DB::scalar("SELECT COUNT(*) FROM pg_index WHERE indrelid = 'organizations'::regclass AND indisprimary AND indnatts > indnkeyatts")
        );

        $this->assertFailureChangesNothing('up', 'has INCLUDE columns that would be lost');
    }

    public function test_primary_key_with_an_include_column_aborts_the_down_direction_too(): void
    {
        Organization::factory()->create();
        $this->replaceOrganizationsPrimaryKeyWithIncludeColumn();

        $this->assertFailureChangesNothing('down', 'has INCLUDE columns that would be lost');
    }

    public function test_plain_single_column_primary_key_is_not_reported_as_having_include_columns(): void
    {
        $this->assertSame(
            0,
            (int) DB::scalar("SELECT COUNT(*) FROM pg_index WHERE indrelid = 'organizations'::regclass AND indisprimary AND indnatts > indnkeyatts")
        );

        // The round trip therefore reaches the end instead of stopping at the new check.
        $this->migrate('down');
        $this->migrate('up');

        $this->assertSame('bigint', $this->idType());
    }

    public function test_expression_index_on_organizations_id_aborts_the_down_direction_too(): void
    {
        DB::statement('CREATE INDEX organizations_id_text_idx ON organizations ((id::text))');

        $this->assertFailureChangesNothing('down', 'index organizations_id_text_idx on organizations.id');
    }

    // ------------------------------------------------------------------------------------------------------------
    // Column detection: genuine references abort, unrelated names do not
    // ------------------------------------------------------------------------------------------------------------

    public function test_unrelated_organization_named_columns_do_not_block_the_conversion(): void
    {
        Organization::factory()->create();
        $this->migrate('down');
        DB::statement('CREATE TABLE organization_stats_probe (id bigserial PRIMARY KEY, organization_count bigint, organization_name text, organizations_total integer)');

        $this->migrate('up');

        $this->assertSame('bigint', $this->idType());
        $this->assertTrue(Schema::hasTable('organization_stats_probe'));
    }

    public function test_parent_organization_id_column_without_a_foreign_key_aborts(): void
    {
        $this->migrate('down');
        DB::statement('CREATE TABLE organization_parent_refs (id bigserial PRIMARY KEY, parent_organization_id uuid)');

        $this->assertFailureChangesNothing('up', 'organization_parent_refs.parent_organization_id');
    }

    // ------------------------------------------------------------------------------------------------------------
    // Foreign key validity (NOT VALID) is preserved, never silently changed
    // ------------------------------------------------------------------------------------------------------------

    public function test_not_valid_foreign_key_stays_not_valid_through_the_round_trip(): void
    {
        Organization::factory()->create();
        $this->recreateDomainForeignKey(validated: false);

        $this->assertFalse($this->domainForeignKeyValidated());
        $shape = $this->shape();

        $this->migrate('down');
        $this->assertFalse($this->domainForeignKeyValidated(), 'NOT VALID must survive down');
        $this->assertSame($shape, $this->shape());

        $this->migrate('up');
        $this->assertFalse($this->domainForeignKeyValidated(), 'NOT VALID must survive up');
        $this->assertSame($shape, $this->shape());
    }

    public function test_validated_foreign_key_stays_validated_through_the_round_trip(): void
    {
        $this->assertTrue($this->domainForeignKeyValidated());

        $this->migrate('down');
        $this->assertTrue($this->domainForeignKeyValidated());

        $this->migrate('up');
        $this->assertTrue($this->domainForeignKeyValidated());
    }

    public function test_orphan_row_behind_a_not_valid_foreign_key_aborts_instead_of_being_hidden(): void
    {
        Organization::factory()->create();
        $this->migrate('down');

        // An orphan can only exist next to a NOT VALID key: drop the key, insert the orphan, re-add the key NOT VALID.
        DB::statement('ALTER TABLE organization_domains DROP CONSTRAINT organization_domains_organization_id_foreign');
        DB::table('organization_domains')->insert([
            'organization_id' => (string) Str::uuid(), 'domain' => 'orphan.example', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement('ALTER TABLE organization_domains ADD CONSTRAINT organization_domains_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE NOT VALID');

        $this->assertFailureChangesNothing('up', 'missing from the map');
    }

    // ------------------------------------------------------------------------------------------------------------
    // Audit metadata on rollback: integers and numeric strings, allowlisted keys only
    // ------------------------------------------------------------------------------------------------------------

    public function test_rollback_converts_integer_and_numeric_string_references_but_not_unrelated_numbers(): void
    {
        $organization = Organization::factory()->create();
        $id = $organization->id;

        DB::table('organization_audit_logs')->insert([
            'actor_type' => 'system', 'action' => 'test.metadata-down',
            'metadata' => json_encode([
                'organization_id' => $id,
                'previous_organization_id' => (string) $id,
                'details' => ['to_organization_id' => (string) $id],
                'attempts' => $id,
                'user_id' => (string) $id,
                'organization_count' => $id,
                'note' => (string) $id,
            ]),
        ]);

        $this->migrate('down');

        $uuid = DB::table('organizations')->where('slug', $organization->slug)->value('id');
        $metadata = json_decode(DB::table('organization_audit_logs')->where('action', 'test.metadata-down')->value('metadata'), true);

        $this->assertSame($uuid, $metadata['organization_id']);
        $this->assertSame($uuid, $metadata['previous_organization_id']);
        $this->assertSame($uuid, $metadata['details']['to_organization_id']);
        $this->assertSame($id, $metadata['attempts']);
        $this->assertSame((string) $id, $metadata['user_id']);
        $this->assertSame($id, $metadata['organization_count']);
        $this->assertSame((string) $id, $metadata['note']);

        $this->migrate('up');

        $metadata = json_decode(DB::table('organization_audit_logs')->where('action', 'test.metadata-down')->value('metadata'), true);
        $this->assertSame($id, $metadata['organization_id']);
        $this->assertSame($id, $metadata['previous_organization_id']);
        $this->assertSame($id, $metadata['details']['to_organization_id']);
        $this->assertSame($id, $metadata['attempts']);
        $this->assertSame((string) $id, $metadata['user_id']);
    }

    // ------------------------------------------------------------------------------------------------------------
    // The failure fingerprint really sees sequence state
    // ------------------------------------------------------------------------------------------------------------

    public function test_failure_fingerprint_detects_sequence_state_changes(): void
    {
        $sequence = DB::scalar("SELECT pg_get_serial_sequence('organizations', 'id')");
        $this->assertNotEmpty($sequence);

        // Sequences are not transactional: nextval() would survive the test's rollback, so the exact state is restored.
        $original = DB::selectOne("SELECT last_value, is_called FROM {$sequence}");
        $lastValue = (int) $original->last_value;
        $isCalled = $original->is_called ? 'true' : 'false';
        $before = $this->fingerprint();

        try {
            DB::scalar("SELECT nextval('{$sequence}')");

            $this->assertNotSame($before, $this->fingerprint(), 'The fingerprint did not notice the sequence change.');
        } finally {
            DB::scalar("SELECT setval('{$sequence}', {$lastValue}, {$isCalled})");
        }

        $restored = DB::selectOne("SELECT last_value, is_called FROM {$sequence}");
        $this->assertSame($lastValue, (int) $restored->last_value);
        $this->assertSame($original->is_called, $restored->is_called);
        $this->assertSame($before, $this->fingerprint(), 'The sequence was not restored.');
    }

    // ------------------------------------------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------------------------------------------

    private function migration(): Migration
    {
        return require app_path('Organization/'.self::MIGRATION);
    }

    /** One migration run as one transaction (a savepoint inside the test's outer transaction). */
    private function migrate(string $direction): void
    {
        $migration = $this->migration();

        DB::transaction(fn () => $migration->{$direction}());
    }

    /**
     * The run must throw, and afterwards the schema and the data must be identical to what they were before it:
     * same columns and types, indexes, constraints, row counts, sequences and ID map.
     */
    private function assertFailureChangesNothing(string $direction, string $expectedMessage): void
    {
        $before = $this->fingerprint();

        try {
            $this->migrate($direction);
            $this->fail('The conversion was expected to abort.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($expectedMessage, $e->getMessage());
        }

        $this->assertSame($before, $this->fingerprint(), 'A failed conversion changed the schema or the data.');
    }

    private function idType(): string
    {
        return DB::scalar(
            "SELECT data_type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'organizations' AND column_name = 'id'"
        );
    }

    private function assertIdentityColumn(): void
    {
        $column = DB::selectOne(
            "SELECT is_identity, identity_generation FROM information_schema.columns
             WHERE table_schema = current_schema() AND table_name = 'organizations' AND column_name = 'id'"
        );

        $this->assertSame('YES', $column->is_identity);
        $this->assertSame('BY DEFAULT', $column->identity_generation);
        $this->assertNotEmpty(DB::scalar("SELECT pg_get_serial_sequence('organizations', 'id')"));
    }

    /** @return list<string> organizations' child tables (every table with a foreign key to it). */
    private function childTables(): array
    {
        return array_map(
            fn ($row) => $row->tbl,
            DB::select("SELECT DISTINCT conrelid::regclass::text AS tbl FROM pg_constraint WHERE contype = 'f' AND confrelid = 'organizations'::regclass ORDER BY 1")
        );
    }

    /** @return array{0: list<string>, 1: string} the table list and its SQL placeholders. */
    private function scope(): array
    {
        $tables = ['organizations', ...$this->childTables()];

        return [$tables, implode(',', array_fill(0, count($tables), '?'))];
    }

    /**
     * Schema objects that must survive a round trip. Independent of the column type, so the UUID and BIGINT schemas
     * can be compared: index definitions (incl. partial), constraint definitions with their delete/update actions,
     * and nullability of the key columns.
     */
    private function shape(): array
    {
        [$tables, $in] = $this->scope();

        $indexes = [];
        foreach (DB::select(
            "SELECT ic.relname AS name, pg_get_indexdef(i.indexrelid) AS def FROM pg_index i
             JOIN pg_class ic ON ic.oid = i.indexrelid WHERE i.indrelid::regclass::text IN ({$in}) ORDER BY 1",
            $tables
        ) as $row) {
            $indexes[$row->name] = $row->def;
        }

        $constraints = [];
        foreach (DB::select(
            "SELECT conrelid::regclass::text AS tbl, conname, contype, pg_get_constraintdef(oid) AS def,
                    confdeltype, confupdtype, condeferrable, convalidated
             FROM pg_constraint WHERE conrelid::regclass::text IN ({$in}) AND contype IN ('f', 'u', 'p', 'c', 'x') ORDER BY 1, 2",
            $tables
        ) as $row) {
            $constraints["{$row->tbl}.{$row->conname}"] = [
                $row->contype, $row->def, $row->confdeltype, $row->confupdtype, $row->condeferrable, $row->convalidated,
            ];
        }

        $nullability = [];
        foreach (DB::select(
            "SELECT attrelid::regclass::text AS tbl, attname, attnotnull FROM pg_attribute
             WHERE NOT attisdropped AND attrelid::regclass::text IN ({$in}) AND attname IN ('id', 'organization_id') ORDER BY 1, 2",
            $tables
        ) as $row) {
            $nullability["{$row->tbl}.{$row->attname}"] = $row->attnotnull;
        }

        return compact('indexes', 'constraints', 'nullability');
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];
        foreach ($this->scope()[0] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /** @return array<string, int> rows referencing the organization, per seeded table. */
    private function rowsFor(int|string $organizationId): array
    {
        $rows = [];
        foreach (self::SEEDED_TABLES as $table) {
            $rows[$table] = DB::table($table)->where('organization_id', $organizationId)->count();
        }

        return $rows;
    }

    /**
     * Turns PRIMARY KEY (id) into PRIMARY KEY (id, slug). Foreign keys need a unique key on id alone, so the
     * primary key is dropped with CASCADE, UNIQUE (id) takes over, and every foreign key is re-added from its saved
     * definition. The child foreign keys therefore all stay in place, and only the primary key differs.
     */
    private function replaceOrganizationsPrimaryKeyWithComposite(): void
    {
        $this->rebuildOrganizationsPrimaryKey([
            'ALTER TABLE organizations ADD CONSTRAINT organizations_id_unique UNIQUE (id)',
            'ALTER TABLE organizations ADD CONSTRAINT organizations_composite_pkey PRIMARY KEY (id, slug)',
        ]);
    }

    /**
     * Turns PRIMARY KEY (id) into PRIMARY KEY (id) INCLUDE (name): still a single key column (so conkey looks
     * ordinary), but the index carries an extra column that a rebuilt PRIMARY KEY (id) would not have.
     */
    private function replaceOrganizationsPrimaryKeyWithIncludeColumn(): void
    {
        $this->rebuildOrganizationsPrimaryKey([
            'ALTER TABLE organizations ADD CONSTRAINT organizations_pkey PRIMARY KEY (id) INCLUDE (name)',
        ]);
    }

    /**
     * Drops organizations' primary key with CASCADE, runs the given statements to build the replacement, then
     * re-adds every foreign key that pointed at it from its saved definition (captured BEFORE the drop, so the
     * actions and validity are unchanged). Only the primary key differs afterwards.
     *
     * @param  list<string>  $statements
     */
    private function rebuildOrganizationsPrimaryKey(array $statements): void
    {
        $foreignKeys = DB::select(
            "SELECT conrelid::regclass::text AS tbl, conname, pg_get_constraintdef(oid) AS def
             FROM pg_constraint WHERE contype = 'f' AND confrelid = 'organizations'::regclass"
        );

        DB::statement('ALTER TABLE organizations DROP CONSTRAINT organizations_pkey CASCADE');

        foreach ($statements as $statement) {
            DB::statement($statement);
        }

        foreach ($foreignKeys as $foreignKey) {
            DB::statement("ALTER TABLE {$foreignKey->tbl} ADD CONSTRAINT \"{$foreignKey->conname}\" {$foreignKey->def}");
        }
    }

    private function recreateDomainForeignKey(bool $validated): void
    {
        DB::statement('ALTER TABLE organization_domains DROP CONSTRAINT organization_domains_organization_id_foreign');
        DB::statement(
            'ALTER TABLE organization_domains ADD CONSTRAINT organization_domains_organization_id_foreign
             FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE'.($validated ? '' : ' NOT VALID')
        );
    }

    private function domainForeignKeyValidated(): bool
    {
        return (bool) DB::scalar("SELECT convalidated FROM pg_constraint WHERE conname = 'organization_domains_organization_id_foreign'");
    }

    /** @return array<string, array{0: int, 1: bool}> last_value and is_called of every sequence in the schema. */
    private function sequenceState(): array
    {
        $state = [];
        $names = DB::select(
            "SELECT relname FROM pg_class WHERE relkind = 'S' AND relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = current_schema()) ORDER BY 1"
        );

        foreach ($names as $row) {
            $sequence = DB::selectOne('SELECT last_value, is_called FROM "'.str_replace('"', '""', $row->relname).'"');
            $state[$row->relname] = [(int) $sequence->last_value, (bool) $sequence->is_called];
        }

        return $state;
    }

    /** Everything a failed conversion must leave untouched. */
    private function fingerprint(): array
    {
        [$tables, $in] = $this->scope();

        $columns = [];
        foreach (DB::select(
            "SELECT table_name, column_name, data_type FROM information_schema.columns
             WHERE table_schema = current_schema() AND table_name IN ({$in}) ORDER BY 1, 2",
            $tables
        ) as $row) {
            $columns["{$row->table_name}.{$row->column_name}"] = $row->data_type;
        }

        return [
            'shape' => $this->shape(),
            'columns' => $columns,
            'counts' => $this->counts(),
            'relations' => array_map(
                fn ($row) => $row->relname,
                DB::select("SELECT relname FROM pg_class WHERE relkind IN ('r', 'S') AND relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = current_schema()) ORDER BY 1")
            ),
            'sequences' => $this->sequenceState(),
            'map' => Schema::hasTable(self::MAP) ? DB::table(self::MAP)->orderBy('new_id')->get()->map(fn ($r) => (array) $r)->all() : null,
            'triggers' => array_map(fn ($row) => $row->tgname, DB::select('SELECT tgname FROM pg_trigger WHERE NOT tgisinternal ORDER BY 1')),
        ];
    }

    /** One row in every table that references an organization (whichever ones the factories and plain inserts reach). */
    private function seedRelatedRows(Organization $organization): void
    {
        $id = $organization->id;

        OrganizationDomain::factory()->create(['organization_id' => $id]);
        OrganizationEntitlement::factory()->create(['organization_id' => $id]);
        OrganizationRedemptionCode::factory()->create(['organization_id' => $id]);
        OrganizationInvitation::factory()->create(['organization_id' => $id]);
        OrganizationMembership::factory()->create(['organization_id' => $id]);
        OrganizationRoleAssignment::factory()->create(['organization_id' => $id]);

        DB::table('organization_contracts')->insert([
            'organization_id' => $id, 'starts_at' => now(), 'ends_at' => now()->addYear(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('organization_audit_logs')->insert([
            'organization_id' => $id, 'actor_type' => 'system', 'action' => 'test.action',
            'target_type' => 'organization', 'target_id' => (string) $id,
            'metadata' => json_encode(['organization_id' => $id, 'note' => 'keep']),
        ]);
    }
}
