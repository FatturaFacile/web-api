<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PostgreSQL schema alignment for late tables and widened enum-backed columns:
 * 1. Allows `overdue` in `invoices.payment_status` (used by overdue invoice alert rules).
 * 2. Widens `chart_of_accounts.standard` so localization packs (`fr_pcg`, `es_pgc`, `de_skr03`, `de_skr04`) can be stored.
 * 3. Adds validated foreign keys to `tenants(id)` for tables created without a constraint, using Laravel's
 *    `<table>_tenant_id_foreign` naming convention.
 */
return new class extends Migration
{
    /**
     * @var array<string, 'CASCADE'|'RESTRICT'>
     */
    private const array TENANT_FOREIGN_KEYS = [
        'assistant_threads' => 'CASCADE',
        'export_jobs' => 'CASCADE',
        'export_schedules' => 'CASCADE',
        'idempotency_keys' => 'CASCADE',
        'notification_alert_rule_hits' => 'CASCADE',
        'notification_alert_rules' => 'CASCADE',
        'notification_deliveries' => 'CASCADE',
        'notification_devices' => 'CASCADE',
        'notification_digest_items' => 'CASCADE',
        'notification_settings' => 'CASCADE',
        'pagopa_iuv_counters' => 'CASCADE',
        'pagopa_payment_notices' => 'CASCADE',
        'vat_settlement_periods' => 'CASCADE',
        'vat_validation_logs' => 'RESTRICT',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (Schema::hasTable('invoices')) {
            DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_payment_status_check');
            DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_payment_status_check CHECK (payment_status::text = ANY (ARRAY['unpaid', 'partially_paid', 'paid', 'overdue']::text[]))");
        }

        if (Schema::hasTable('chart_of_accounts')) {
            DB::statement('ALTER TABLE chart_of_accounts DROP CONSTRAINT IF EXISTS chart_of_accounts_standard_check');
            DB::statement("ALTER TABLE chart_of_accounts ALTER COLUMN standard TYPE varchar(40), ALTER COLUMN standard SET DEFAULT 'it_general_ledger'");
        }

        if (! Schema::hasTable('tenants')) {
            return;
        }

        foreach (self::TENANT_FOREIGN_KEYS as $table => $action) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            $exists = DB::selectOne(<<<'SQL'
                SELECT 1
                FROM pg_constraint k
                JOIN pg_class cls ON cls.oid = k.conrelid
                JOIN pg_namespace ns ON ns.oid = cls.relnamespace AND ns.nspname = current_schema()
                JOIN pg_attribute att ON att.attrelid = cls.oid AND att.attname = 'tenant_id' AND k.conkey = ARRAY[att.attnum]
                WHERE k.contype = 'f' AND k.confrelid = 'tenants'::regclass AND cls.relname = ?
                LIMIT 1
            SQL, [$table]);

            if ($exists !== null) {
                continue;
            }

            $constraint = "{$table}_tenant_id_foreign";
            DB::statement(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE %s',
                $this->quote($table),
                $this->quote($constraint),
                $action,
            ));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_keys(self::TENANT_FOREIGN_KEYS) as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::statement(sprintf(
                'ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s',
                $this->quote($table),
                $this->quote("{$table}_tenant_id_foreign"),
            ));
        }
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
};
