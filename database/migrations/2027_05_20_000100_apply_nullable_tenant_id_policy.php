<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantSchemaPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner policy: every table has a nullable UUID tenant_id (NULL = global data visible to every tenant, used only for
 * shared data; every tenant record carries its tenant_id).
 *
 * - The tables listed in TenantSchemaPolicy::EXCLUDED_TABLES are left alone (framework tables, tenants, users).
 * - A table without tenant_id gets the nullable column, a foreign key to tenants and an index. A child table listed in
 *   TenantSchemaPolicy::CHILD_TABLES is backfilled from its parent.
 * - A table whose tenant_id is NOT NULL is relaxed to NULL (the application layer, BelongsToTenant, refuses to store a
 *   null tenant for models that do not allow global records).
 *
 * Additive and relaxing only, idempotent (every step checks the current state), nothing is dropped. It runs one
 * statement per table outside a transaction, so a long schema never holds hundreds of table locks at once and a re-run
 * after an interruption finishes the work.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->upWithSchemaBuilder();

            return;
        }

        $schema = (string) DB::selectOne('select current_schema() as name')->name;

        $columns = DB::table('information_schema.columns')
            ->where('table_schema', $schema)
            ->where('column_name', 'tenant_id')
            ->pluck('is_nullable', 'table_name')
            ->all();

        $tables = DB::table('information_schema.tables')
            ->where('table_schema', $schema)
            ->where('table_type', 'BASE TABLE')
            ->orderBy('table_name')
            ->pluck('table_name')
            ->all();

        foreach ($tables as $table) {
            if (TenantSchemaPolicy::isExcluded($table) || $table === 'tenants') {
                continue;
            }

            if (! array_key_exists($table, $columns)) {
                $this->addColumn($table);
                $this->backfillFromParent($table, $tables, $columns);

                continue;
            }

            if ($columns[$table] === 'NO') {
                DB::statement('alter table "'.$table.'" alter column "tenant_id" drop not null');
            }

            $this->backfillFromParent($table, $tables, $columns);
        }
    }

    /**
     * Relaxing NOT NULL again could fail on rows written meanwhile and re-imposing the old constraint is not a safe
     * rollback of a policy change, so nothing is undone and no data is dropped.
     */
    public function down(): void {}

    private function addColumn(string $table): void
    {
        DB::statement('alter table "'.$table.'" add column if not exists "tenant_id" uuid null');

        $constraint = $this->identifier($table, 'tenant_id_foreign');
        $exists = DB::table('pg_constraint')->where('conname', $constraint)->where('conrelid', DB::raw('\''.$table.'\'::regclass'))->exists();

        if (! $exists) {
            DB::statement('alter table "'.$table.'" add constraint "'.$constraint.'" foreign key ("tenant_id") references "tenants" ("id") on delete cascade');
        }

        DB::statement('create index if not exists "'.$this->identifier($table, 'tenant_id_index').'" on "'.$table.'" ("tenant_id")');
    }

    /**
     * @param  list<string>  $tables
     * @param  array<string, string>  $columns
     */
    private function backfillFromParent(string $table, array $tables, array $columns): void
    {
        $parent = TenantSchemaPolicy::parentOf($table);

        if ($parent === null || ! in_array($parent[0], $tables, true) || ! array_key_exists($parent[0], $columns)) {
            return;
        }

        DB::statement(sprintf(
            'update "%1$s" as child set "tenant_id" = parent."tenant_id" from "%2$s" as parent where child."%3$s" = parent."id" and child."tenant_id" is null and parent."tenant_id" is not null',
            $table,
            $parent[0],
            $parent[1],
        ));
    }

    /**
     * Index and constraint names are capped at 63 bytes by PostgreSQL: a long table name is shortened with a hash.
     */
    private function identifier(string $table, string $suffix): string
    {
        $name = $table.'_'.$suffix;

        return strlen($name) <= 63 ? $name : substr($table, 0, 63 - strlen($suffix) - 10).'_'.substr(md5($table), 0, 8).'_'.$suffix;
    }

    private function upWithSchemaBuilder(): void
    {
        foreach (Schema::getTables() as $row) {
            $table = $row['name'];

            if (TenantSchemaPolicy::isExcluded($table) || $table === 'tenants') {
                continue;
            }

            if (! Schema::hasColumn($table, 'tenant_id')) {
                Schema::table($table, static function (Blueprint $blueprint): void {
                    $blueprint->uuid('tenant_id')->nullable()->index();
                });

                continue;
            }

            Schema::table($table, static function (Blueprint $blueprint): void {
                $blueprint->uuid('tenant_id')->nullable()->change();
            });
        }
    }
};
