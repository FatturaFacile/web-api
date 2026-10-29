<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'warehouse_zones',
            'warehouse_aisles',
            'warehouse_racks',
            'warehouse_bins',
            'stock_quants',
            'stock_lots',
            'stock_serial_numbers',
            'stock_reservations',
            'stock_reorder_rules',
            'stock_movements',
            'stock_pick_lists',
            'stock_pick_lines',
            'stock_packing_lists',
            'inventory_count_sessions',
            'inventory_count_lines',
            'inventory_adjustments',
            'inventory_adjustment_lines',
            'inventory_valuation_snapshots',
            'inventory_audit_entries',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->softDeletes();
                });
            }
        }

        if (Schema::hasTable('stock_movements')) {
            Schema::table('stock_movements', function (Blueprint $t): void {
                if (Schema::hasColumn('stock_movements', 'product_id')) {
                    $t->uuid('product_id')->nullable()->change();
                }
                if (Schema::hasColumn('stock_movements', 'warehouse_id')) {
                    $t->uuid('warehouse_id')->nullable()->change();
                }
                if (Schema::hasColumn('stock_movements', 'type')) {
                    $t->string('type', 50)->nullable()->change();
                }
            });
        }
    }

    public function down(): void
    {
        $tables = [
            'warehouse_zones',
            'warehouse_aisles',
            'warehouse_racks',
            'warehouse_bins',
            'stock_quants',
            'stock_lots',
            'stock_serial_numbers',
            'stock_reservations',
            'stock_reorder_rules',
            'stock_movements',
            'stock_pick_lists',
            'stock_pick_lines',
            'stock_packing_lists',
            'inventory_count_sessions',
            'inventory_count_lines',
            'inventory_adjustments',
            'inventory_adjustment_lines',
            'inventory_valuation_snapshots',
            'inventory_audit_entries',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->dropSoftDeletes();
                });
            }
        }
    }
};
