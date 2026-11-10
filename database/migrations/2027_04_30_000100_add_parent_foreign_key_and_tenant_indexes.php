<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive, idempotent performance indexes found by the production-readiness audit.
 *
 * - Parent foreign-key indexes on the hot line tables (invoice lines, order lines, subscriptions, stock...),
 *   so that reading the lines of a document no longer scans the whole table of every tenant.
 * - A tenant-leading index on every tenant table that had none (composite with the parent foreign key when one exists).
 *
 * Plain CREATE INDEX IF NOT EXISTS (migrations run in a transaction, so no CONCURRENTLY). No existing index is dropped.
 */
return new class extends Migration
{
    /**
     * Every index created by this migration, as [table, columns].
     *
     * @return array<int, array{0: string, 1: array<int, string>}>
     */
    public static function indexDefinitions(): array
    {
        $parentForeignKeys = [
            'invoice_items' => [['invoice_id']],
            'sales_order_items' => [['sales_order_id']],
            'quote_items' => [['quote_id']],
            'delivery_note_items' => [['delivery_note_id']],
            'esm_ticket_messages' => [['ticket_id']],
            'esm_work_order_items' => [['work_order_id']],
            'payroll_payslip_lines' => [['payslip_id']],
            'stock_quants' => [['stock_item_id'], ['lot_id']],
            'bank_statement_lines' => [['bank_statement_id']],
            'sub_churn_predictions' => [['subscription_id']],
            'sub_dunning_records' => [['subscription_id']],
            'sub_partner_commissions' => [['subscription_id']],
            'sub_performance_obligations' => [['revenue_contract_id']],
            'sub_plan_features' => [['plan_id']],
            'sub_plans' => [['product_id']],
            'sub_revenue_contracts' => [['subscription_id']],
            'sub_revenue_recognition_entries' => [['performance_obligation_id']],
            'sub_revenue_schedules' => [['performance_obligation_id']],
            'sub_subscription_items' => [['plan_id'], ['product_id'], ['metric_id']],
            'sub_subscriptions' => [['plan_id']],
            'sub_usage_events' => [['subscription_id'], ['subscription_item_id']],
        ];

        // table => parent foreign key used in the composite tenant index (null: tenant_id alone).
        $tenantIndexes = [
            'delivery_note_items' => 'delivery_note_id',
            'eam_asset_components' => 'parent_asset_id',
            'eam_asset_custodians' => 'asset_id',
            'eam_asset_documents' => 'asset_id',
            'eam_project_asset_allocations' => 'asset_id',
            'eam_work_order_parts' => 'work_order_id',
            'enasarco_parameters' => null,
            'esm_parts_consumptions' => 'work_order_id',
            'esm_service_visits' => 'work_order_id',
            'esm_ticket_time_entries' => 'ticket_id',
            'esm_work_order_items' => 'work_order_id',
            'hr_certifications' => 'employee_id',
            'hr_employee_lifecycle_events' => 'employee_id',
            'invoice_credit_applications' => 'credit_note_id',
            'invoice_items' => 'invoice_id',
            'leave_requests' => 'user_id',
            'password_reset_tokens' => null,
            'payroll_payment_batches' => 'payment_batch_id',
            'payroll_short_time_claims' => null,
            'payroll_short_time_lines' => 'employee_id',
            'quote_items' => 'quote_id',
            'riba_presentations' => null,
            'sales_order_holds' => 'sales_order_id',
            'sales_order_items' => 'sales_order_id',
            'sub_churn_predictions' => 'subscription_id',
            'sub_dunning_policies' => null,
            'sub_dunning_records' => 'subscription_id',
            'sub_invoice_schedules' => null,
            'sub_partner_commissions' => 'subscription_id',
            'sub_performance_obligations' => 'revenue_contract_id',
            'sub_plan_features' => 'plan_id',
            'sub_pricing_tiers' => null,
            'sub_revenue_recognition_entries' => 'performance_obligation_id',
            'sub_revenue_schedules' => 'performance_obligation_id',
            'sub_subscription_items' => 'plan_id',
            'sub_subscription_versions' => null,
            'sub_usage_aggregations' => null,
            'tenant_access_requests' => null,
            'time_schedule_group_rules' => null,
            'visitor_kiosks' => null,
            'withholding_settlement_lines' => 'withholding_settlement_id',
        ];

        $definitions = [];

        foreach ($parentForeignKeys as $table => $columnSets) {
            foreach ($columnSets as $columns) {
                $definitions[] = [$table, $columns];
            }
        }

        foreach ($tenantIndexes as $table => $parent) {
            $definitions[] = [$table, $parent === null ? ['tenant_id'] : ['tenant_id', $parent]];
        }

        return $definitions;
    }

    /**
     * Deterministic index name, shortened with a hash when it would exceed PostgreSQL's 63 characters.
     *
     * @param  array<int, string>  $columns
     */
    public static function indexName(string $table, array $columns): string
    {
        $name = $table.'_'.implode('_', $columns).'_idx';

        if (strlen($name) <= 63) {
            return $name;
        }

        return substr($name, 0, 54).'_'.substr(hash('crc32b', $name), 0, 8);
    }

    public function up(): void
    {
        foreach (self::indexDefinitions() as [$table, $columns]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue 2;
                }
            }

            $quotedColumns = implode(', ', array_map(static fn (string $column): string => '"'.$column.'"', $columns));

            DB::statement(sprintf(
                'CREATE INDEX IF NOT EXISTS "%s" ON "%s" (%s)',
                self::indexName($table, $columns),
                $table,
                $quotedColumns,
            ));
        }
    }

    public function down(): void
    {
        foreach (self::indexDefinitions() as [$table, $columns]) {
            DB::statement(sprintf('DROP INDEX IF EXISTS "%s"', self::indexName($table, $columns)));
        }
    }
};
