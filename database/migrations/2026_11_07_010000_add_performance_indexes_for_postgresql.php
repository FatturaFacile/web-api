<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Composite performance indexes for multi-tenant PostgreSQL queries.
     *
     * @var array<string, list<array{columns: list<string>, name: string}>>
     */
    private const INDEX_MAP = [
        'invoices' => [
            ['columns' => ['tenant_id', 'contact_id'], 'name' => 'invoices_tenant_id_contact_id_idx'],
        ],
        'quotes' => [
            ['columns' => ['tenant_id', 'contact_id'], 'name' => 'quotes_tenant_id_contact_id_idx'],
            ['columns' => ['tenant_id', 'status'], 'name' => 'quotes_tenant_id_status_idx'],
            ['columns' => ['tenant_id', 'date'], 'name' => 'quotes_tenant_id_date_idx'],
        ],
        'sales_orders' => [
            ['columns' => ['tenant_id', 'contact_id'], 'name' => 'sales_orders_tenant_id_contact_id_idx'],
            ['columns' => ['tenant_id', 'status'], 'name' => 'sales_orders_tenant_id_status_idx'],
            ['columns' => ['tenant_id', 'date'], 'name' => 'sales_orders_tenant_id_date_idx'],
        ],
        'delivery_notes' => [
            ['columns' => ['tenant_id', 'contact_id'], 'name' => 'delivery_notes_tenant_id_contact_id_idx'],
            ['columns' => ['tenant_id', 'date'], 'name' => 'delivery_notes_tenant_id_date_idx'],
        ],
        'payments' => [
            ['columns' => ['tenant_id', 'paid_at'], 'name' => 'payments_tenant_id_paid_at_idx'],
        ],
        'passive_invoices' => [
            ['columns' => ['tenant_id', 'supplier_id'], 'name' => 'passive_invoices_tenant_id_supplier_id_idx'],
            ['columns' => ['tenant_id', 'date'], 'name' => 'passive_invoices_tenant_id_date_idx'],
            ['columns' => ['tenant_id', 'is_reconciled'], 'name' => 'passive_invoices_tenant_id_is_reconciled_idx'],
        ],
        'purchase_orders' => [
            ['columns' => ['tenant_id', 'supplier_id'], 'name' => 'purchase_orders_tenant_id_supplier_id_idx'],
            ['columns' => ['tenant_id', 'status'], 'name' => 'purchase_orders_tenant_id_status_idx'],
            ['columns' => ['tenant_id', 'date'], 'name' => 'purchase_orders_tenant_id_date_idx'],
        ],
        'tickets' => [
            ['columns' => ['tenant_id', 'client_id'], 'name' => 'tickets_tenant_id_client_id_idx'],
            ['columns' => ['tenant_id', 'assigned_to_user_id'], 'name' => 'tickets_tenant_id_assigned_to_user_id_idx'],
        ],
        'work_orders' => [
            ['columns' => ['tenant_id', 'client_id'], 'name' => 'work_orders_tenant_id_client_id_idx'],
            ['columns' => ['tenant_id', 'technician_user_id'], 'name' => 'work_orders_tenant_id_technician_user_id_idx'],
            ['columns' => ['tenant_id', 'status'], 'name' => 'work_orders_tenant_id_status_idx'],
            ['columns' => ['tenant_id', 'date'], 'name' => 'work_orders_tenant_id_date_idx'],
        ],
        'contacts' => [
            ['columns' => ['tenant_id', 'vat_number'], 'name' => 'contacts_tenant_id_vat_number_idx'],
            ['columns' => ['tenant_id', 'fiscal_code'], 'name' => 'contacts_tenant_id_fiscal_code_idx'],
        ],
        'attachments' => [
            ['columns' => ['tenant_id', 'attachable_type', 'attachable_id'], 'name' => 'attachments_tenant_attachable_idx'],
        ],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::INDEX_MAP as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $tableBlueprint) use ($table, $indexes): void {
                foreach ($indexes as $index) {
                    $hasAllColumns = true;
                    foreach ($index['columns'] as $col) {
                        if (! Schema::hasColumn($table, $col)) {
                            $hasAllColumns = false;
                            break;
                        }
                    }

                    if ($hasAllColumns) {
                        $tableBlueprint->index($index['columns'], $index['name']);
                    }
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::INDEX_MAP as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $tableBlueprint) use ($indexes): void {
                foreach ($indexes as $index) {
                    $tableBlueprint->dropIndex($index['name']);
                }
            });
        }
    }
};
