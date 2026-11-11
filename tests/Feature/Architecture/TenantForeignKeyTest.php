<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Backup\TenantBackupService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Models\LedgerSetting;
use Modules\Invoicing\Models\CustomerGroup;
use Tests\Support\TenantRecordSeeder;

uses(RefreshDatabase::class);

/**
 * Every tenant_id column of an ordinary table is a foreign key to tenants(id), validated, with ON DELETE RESTRICT on the
 * evidence tables (journals, ledgers, audit trails, payroll...) and CASCADE on the others. Partitions, partitioned tables and
 * the high-volume infrastructure tables are the documented exceptions (see the migration
 * 2027_05_12_000200_add_tenant_foreign_keys).
 *
 * A table created by a new migration without the key fails here: add
 * `$table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete()` (or restrictOnDelete() for evidence data).
 */
function tenantForeignKeyMigration(): object
{
    return require database_path('migrations/2027_05_12_000200_add_tenant_foreign_keys.php');
}

/**
 * @return list<string>
 */
function tablesExpectedToHaveTenantKey(): array
{
    $rows = DB::select(<<<'SQL'
        SELECT cls.relname AS table_name
        FROM pg_class cls
        JOIN pg_namespace ns ON ns.oid = cls.relnamespace AND ns.nspname = current_schema()
        JOIN pg_attribute att ON att.attrelid = cls.oid AND att.attname = 'tenant_id' AND NOT att.attisdropped
        WHERE cls.relkind = 'r' AND NOT cls.relispartition AND cls.relname <> 'tenants' AND att.atttypid = 'uuid'::regtype
        ORDER BY cls.relname
    SQL);

    return array_values(array_filter(
        array_map(static fn (object $row): string => (string) $row->table_name, $rows),
        static fn (string $table): bool => ! in_array($table, TenantBackupService::APPEND_ONLY_TABLES, true) && ! str_starts_with($table, 'enterprise_audit_ledger') && ! str_starts_with($table, 'transactional_outbox') && ! str_starts_with($table, 'transactional_inbox'),
    ));
}

test('every tenant table has a validated key to tenants on tenant_id', function (): void {
    $keyed = collect(DB::select(<<<'SQL'
        SELECT cls.relname AS table_name, k.convalidated AS validated
        FROM pg_constraint k
        JOIN pg_class cls ON cls.oid = k.conrelid
        JOIN pg_attribute att ON att.attrelid = cls.oid AND att.attname = 'tenant_id' AND k.conkey = ARRAY[att.attnum]
        WHERE k.contype = 'f' AND k.confrelid = 'tenants'::regclass
    SQL))->mapWithKeys(static fn (object $row): array => [(string) $row->table_name => (bool) $row->validated]);

    $missing = array_values(array_filter(tablesExpectedToHaveTenantKey(), static fn (string $table): bool => ! $keyed->has($table)));
    $notValidated = $keyed->filter(static fn (bool $validated): bool => ! $validated)->keys()->all();

    expect($missing)->toBe([], 'Tables with a tenant_id and no foreign key to tenants: '.implode(', ', $missing))
        ->and($notValidated)->toBe([], 'Foreign keys left NOT VALID (orphan rows): '.implode(', ', $notValidated));
});

test('the delete action follows the class of the table', function (): void {
    $actions = collect(DB::select(<<<'SQL'
        SELECT cls.relname AS table_name, k.confdeltype AS action
        FROM pg_constraint k
        JOIN pg_class cls ON cls.oid = k.conrelid
        WHERE k.contype = 'f' AND k.confrelid = 'tenants'::regclass AND k.conname LIKE '%\_tenant\_fk'
    SQL))->mapWithKeys(static fn (object $row): array => [(string) $row->table_name => (string) $row->action]);

    $migration = tenantForeignKeyMigration();
    $wrong = [];

    foreach ($actions as $table => $action) {
        $expected = $migration::deleteAction($table) === 'RESTRICT' ? 'r' : 'c';

        if ($action !== $expected) {
            $wrong[] = "{$table}: {$action}, expected {$expected}";
        }
    }

    expect($actions->count())->toBeGreaterThan(250)
        ->and($wrong)->toBe([])
        ->and($actions['journal_entries'])->toBe('r')
        ->and($actions['customer_ledger'])->toBe('r')
        ->and($actions['project_audit_entries'])->toBe('r')
        ->and($actions['cost_centers'])->toBe('c');
});

test('a row of a tenant that does not exist is refused, and a tenant with evidence cannot be deleted', function (): void {
    $tenant = TenantRecordSeeder::tenant('Alfa');
    $ghost = new Tenant(['id' => (string) Str::uuid7()]);
    $ghost->id = (string) Str::uuid7();

    expect(fn () => DB::transaction(static fn (): string => TenantRecordSeeder::insert(CustomerGroup::class, $ghost, true)))->toThrow(QueryException::class, 'foreign key');

    $key = DB::transaction(static fn (): string => TenantRecordSeeder::insert(LedgerSetting::class, $tenant, true));

    expect(fn () => DB::transaction(static fn () => DB::table('tenants')->where('id', $tenant->id)->delete()))->toThrow(QueryException::class, 'gl_ledger_settings')
        ->and(DB::table('gl_ledger_settings')->where('id', $key)->exists())->toBeTrue();
});

test('the constraint name fits PostgreSQL and is stable', function (): void {
    $migration = tenantForeignKeyMigration();
    $long = 'a_table_with_a_very_long_name_that_goes_beyond_the_postgresql_identifier_limit_of_63_characters';

    expect($migration::constraintName('cost_centers'))->toBe('cost_centers_tenant_fk')
        ->and(strlen($migration::constraintName($long)))->toBeLessThanOrEqual(63)
        ->and($migration::constraintName($long))->toBe($migration::constraintName($long))
        ->and($migration::constraintName($long))->toEndWith('_tenant_fk');
});

test('running the migration again changes nothing, and a table with orphan rows keeps a NOT VALID key and is reported', function (): void {
    $before = DB::table('pg_constraint')->where('conname', 'like', '%\_tenant\_fk')->count();

    DB::statement('CREATE TABLE tmp_orphan_check (id uuid PRIMARY KEY, tenant_id uuid)');
    DB::table('tmp_orphan_check')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => (string) Str::uuid7()]);
    Log::spy();

    tenantForeignKeyMigration()->up();

    $orphanKey = DB::selectOne("SELECT convalidated FROM pg_constraint WHERE conname = 'tmp_orphan_check_tenant_fk'");
    $after = DB::table('pg_constraint')->where('conname', 'like', '%\_tenant\_fk')->count();

    expect($orphanKey)->not->toBeNull()
        ->and((bool) $orphanKey->convalidated)->toBeFalse()
        ->and($after)->toBe($before + 1);

    Log::shouldHaveReceived('warning')->once();

    expect(fn () => DB::table('tmp_orphan_check')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => (string) Str::uuid7()]))->toThrow(QueryException::class);
});

test('the migration can be rolled back to the keys it added only', function (): void {
    $migration = tenantForeignKeyMigration();
    $existing = DB::table('pg_constraint')->where('contype', 'f')->where('confrelid', DB::raw("'tenants'::regclass"))->count();

    $migration->down();
    $afterDown = DB::table('pg_constraint')->where('contype', 'f')->where('confrelid', DB::raw("'tenants'::regclass"))->count();
    $migration->up();
    $afterUp = DB::table('pg_constraint')->where('contype', 'f')->where('confrelid', DB::raw("'tenants'::regclass"))->count();

    expect($afterDown)->toBeLessThan($existing)
        ->and($afterDown)->toBeGreaterThan(400)
        ->and($afterUp)->toBe($existing);
});
