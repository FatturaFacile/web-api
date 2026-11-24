<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FatturaFacile/FatturaFacile#144 — per-item valuation method override, item volume
 * (landed cost allocation by volume) and optional per-category GL account overrides.
 *
 * Category `valuation_method` keeps accepting the legacy value `moving_average`,
 * which the application resolves as an alias of `weighted_average`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // StockValuationSnapshot extends BaseEnterpriseModel (SoftDeletes) but its table was created
        // without deleted_at, which breaks POST valuation/snapshot.
        if (Schema::hasTable('stock_valuation_snapshots') && ! Schema::hasColumn('stock_valuation_snapshots', 'deleted_at')) {
            Schema::table('stock_valuation_snapshots', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }

        if (Schema::hasTable('stock_items')) {
            Schema::table('stock_items', function (Blueprint $table): void {
                if (! Schema::hasColumn('stock_items', 'valuation_method')) {
                    // null = inherit from category; fifo | weighted_average | standard_cost
                    $table->string('valuation_method', 50)->nullable();
                }
                if (! Schema::hasColumn('stock_items', 'volume_m3')) {
                    $table->decimal('volume_m3', 10, 4)->nullable();
                }
            });
        }

        if (Schema::hasTable('stock_item_categories')) {
            Schema::table('stock_item_categories', function (Blueprint $table): void {
                foreach (['inventory_account_id', 'cogs_account_id', 'ppv_account_id', 'landed_cost_clearing_account_id'] as $column) {
                    if (! Schema::hasColumn('stock_item_categories', $column)) {
                        $table->uuid($column)->nullable();
                    }
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('stock_item_categories')) {
            Schema::table('stock_item_categories', function (Blueprint $table): void {
                foreach (['inventory_account_id', 'cogs_account_id', 'ppv_account_id', 'landed_cost_clearing_account_id'] as $column) {
                    if (Schema::hasColumn('stock_item_categories', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('stock_items')) {
            Schema::table('stock_items', function (Blueprint $table): void {
                if (Schema::hasColumn('stock_items', 'volume_m3')) {
                    $table->dropColumn('volume_m3');
                }
                if (Schema::hasColumn('stock_items', 'valuation_method')) {
                    $table->dropColumn('valuation_method');
                }
            });
        }

        if (Schema::hasTable('stock_valuation_snapshots') && Schema::hasColumn('stock_valuation_snapshots', 'deleted_at')) {
            Schema::table('stock_valuation_snapshots', function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }
    }
};
