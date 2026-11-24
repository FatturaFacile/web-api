<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * One stock ledger: the app's stock movements go through the inventory module (ProductStockLedger). Additive only.
 *
 * - `stock_items.product_id` becomes unique per tenant among live items, so a product has one stock item. The index is
 *   created only when no product is linked twice yet: duplicates are reported, never changed by the migration.
 * - `stock_movements.legacy_migrated_at` and `legacy_migration_movement_id` mark the app-style rows (product, no stock
 *   item) that LegacyStockMovementImporter has booked into the module, so they are never booked twice.
 * - `stock_shortfalls` keeps what was sold beyond the stock until incoming stock settles it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $duplicates = DB::table('stock_items')
                ->whereNotNull('product_id')
                ->whereNull('deleted_at')
                ->groupBy('tenant_id', 'product_id')
                ->havingRaw('COUNT(*) > 1')
                ->select(['tenant_id', 'product_id'])
                ->get()
                ->count();

            if ($duplicates === 0) {
                DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_stock_items_tenant_product ON stock_items (tenant_id, product_id) WHERE product_id IS NOT NULL AND deleted_at IS NULL');
            } else {
                Log::warning('stock_items: some products are linked to more than one stock item; the unique index was not created.', ['products' => $duplicates]);
            }
        }

        Schema::table('stock_movements', function (Blueprint $table): void {
            if (! Schema::hasColumn('stock_movements', 'legacy_migrated_at')) {
                $table->timestamp('legacy_migrated_at')->nullable()->index();
            }
            if (! Schema::hasColumn('stock_movements', 'legacy_migration_movement_id')) {
                $table->uuid('legacy_migration_movement_id')->nullable()->index();
            }
        });

        if (! Schema::hasTable('stock_shortfalls')) {
            Schema::create('stock_shortfalls', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('stock_item_id');
                $table->uuid('product_id')->nullable()->index();
                $table->uuid('warehouse_id');
                $table->uuid('warehouse_bin_id');
                $table->decimal('quantity', 14, 4);
                $table->decimal('quantity_open', 14, 4);
                $table->uuid('cover_movement_id')->nullable();
                $table->string('origin_document_type', 255)->nullable();
                $table->uuid('origin_document_id')->nullable();
                $table->timestamp('settled_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('stock_item_id')->references('id')->on('stock_items')->cascadeOnDelete();
                $table->foreign('warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();
                $table->foreign('warehouse_bin_id')->references('id')->on('warehouse_bins')->restrictOnDelete();
                $table->index(['tenant_id', 'stock_item_id', 'warehouse_id', 'quantity_open'], 'idx_shortfalls_open');
                $table->index(['origin_document_type', 'origin_document_id'], 'idx_shortfalls_origin');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_shortfalls');

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropColumn(['legacy_migrated_at', 'legacy_migration_movement_id']);
        });

        DB::statement('DROP INDEX IF EXISTS uq_stock_items_tenant_product');
    }
};
