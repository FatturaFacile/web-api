<?php

declare(strict_types=1);

use App\Services\Backup\TenantBackupService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Foreign keys from tenant_id to tenants(id) on the tables that have the column and no such key (about 340 at the time of
 * writing: 479 tables already had one, with ON DELETE CASCADE).
 *
 * Safe on a large schema:
 * - one table at a time, each key added as NOT VALID (a short lock, no scan of the table) and then validated when no orphan
 *   row exists (VALIDATE takes only a SHARE UPDATE EXCLUSIVE lock, readers and writers go on). A table that holds orphan
 *   rows keeps the key NOT VALID, which already protects new rows, and is reported in the log for a clean-up; the key can
 *   then be validated by hand with `ALTER TABLE ... VALIDATE CONSTRAINT <table>_tenant_fk`;
 * - additive: no column or table is touched, and the nullability of tenant_id is left as it is (a null tenant_id passes the key);
 * - idempotent: tables that already have a key to tenants on tenant_id are skipped.
 *
 * ON DELETE, by class of table:
 * - evidence tables (journals, ledgers, audit trails, retention and consent records, payroll, legal archives, append-only logs and histories): RESTRICT. Deleting
 *   a tenant must never silently erase accounting, payroll or audit evidence; the tenant has to be purged on purpose.
 * - every other tenant table: CASCADE, the convention of the 476 keys that already exist (the data of a tenant has no meaning
 *   without it).
 *
 * Left without a key, on purpose: partitions and partitioned tables (PostgreSQL cannot add a NOT VALID key to a partitioned table)
 * the high-volume append-only infrastructure tables (the enterprise audit ledger, the transactional outbox and inbox) and the
 * immutable histories listed in TenantBackupService::APPEND_ONLY_TABLES, which by design have no foreign key (nothing may
 * cascade into them and a restore never deletes their rows).
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const array EVIDENCE_PATTERNS = ['journal', 'ledger', 'audit', 'retention', 'consent', 'signator', 'archive', 'payroll', 'payslip', 'legal_hold', 'preservation'];

    /**
     * @var list<string>
     */
    private const array EXCLUDED_PREFIXES = ['enterprise_audit_ledger', 'transactional_outbox', 'transactional_inbox'];

    /**
     * The constraint name of a table (63 characters at most).
     */
    public static function constraintName(string $table): string
    {
        $name = $table.'_tenant_fk';

        return strlen($name) <= 63 ? $name : substr($table, 0, 42).'_'.substr(md5($table), 0, 8).'_tenant_fk';
    }

    /**
     * The ON DELETE action of the key of a table.
     */
    public static function deleteAction(string $table): string
    {
        foreach (self::EVIDENCE_PATTERNS as $pattern) {
            if (str_contains($table, $pattern)) {
                return 'RESTRICT';
            }
        }

        // Append-only logs and histories (access logs, message logs, password histories...): nothing may cascade into them.
        if (preg_match('/(^|_)(logs?|histor(y|ies))($|_)/', $table) === 1) {
            return 'RESTRICT';
        }

        return 'CASCADE';
    }

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('tenants')) {
            return;
        }

        foreach ($this->tablesWithoutKey() as $table) {
            $constraint = self::constraintName($table);
            $action = self::deleteAction($table);

            DB::statement(sprintf('ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE %s NOT VALID', $this->quote($table), $this->quote($constraint), $action));

            $orphans = DB::selectOne(sprintf(
                'SELECT EXISTS (SELECT 1 FROM %1$s t WHERE t.tenant_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM tenants WHERE tenants.id = t.tenant_id)) AS orphans',
                $this->quote($table),
            ));

            if ((bool) $orphans->orphans) {
                Log::warning("Table [{$table}] has rows of a tenant that does not exist: the key [{$constraint}] stays NOT VALID until they are cleaned up.");

                continue;
            }

            DB::statement(sprintf('ALTER TABLE %s VALIDATE CONSTRAINT %s', $this->quote($table), $this->quote($constraint)));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $tables = DB::select(
            "SELECT c.conrelid::regclass::text AS table_name, c.conname FROM pg_constraint c WHERE c.contype = 'f' AND c.conname LIKE '%\\_tenant\\_fk' AND c.confrelid = 'tenants'::regclass",
        );

        foreach ($tables as $row) {
            DB::statement(sprintf('ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s', $row->table_name, $this->quote($row->conname)));
        }
    }

    /**
     * Ordinary tables (not partitions, not partitioned) with a uuid tenant_id and no key from it to tenants.
     *
     * @return list<string>
     */
    private function tablesWithoutKey(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT cls.relname AS table_name
            FROM pg_class cls
            JOIN pg_namespace ns ON ns.oid = cls.relnamespace AND ns.nspname = current_schema()
            JOIN pg_attribute att ON att.attrelid = cls.oid AND att.attname = 'tenant_id' AND NOT att.attisdropped
            WHERE cls.relkind = 'r'
              AND NOT cls.relispartition
              AND cls.relname <> 'tenants'
              AND att.atttypid = 'uuid'::regtype
              AND NOT EXISTS (
                  SELECT 1 FROM pg_constraint k
                  WHERE k.conrelid = cls.oid AND k.contype = 'f' AND k.confrelid = 'tenants'::regclass AND k.conkey = ARRAY[att.attnum]
              )
            ORDER BY cls.relname
        SQL);

        return array_values(array_filter(
            array_map(static fn (object $row): string => (string) $row->table_name, $rows),
            static function (string $table): bool {
                if (in_array($table, TenantBackupService::APPEND_ONLY_TABLES, true)) {
                    return false;
                }

                foreach (self::EXCLUDED_PREFIXES as $prefix) {
                    if (str_starts_with($table, $prefix)) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
};
