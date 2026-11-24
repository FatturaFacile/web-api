<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Picking strategy, shelf life and packaging data on the item master, virtual and third-party warehouses, two-level
     * lots with their genealogy, and the quants each reservation holds. Everything is nullable or defaulted, so existing
     * rows keep their meaning: an item without a strategy follows its warehouse, then FIFO.
     */
    public function up(): void
    {
        Schema::table('stock_items', function (Blueprint $table): void {
            $table->uuid('product_id')->nullable()->index();
            $table->string('picking_strategy', 10)->nullable();
            $table->integer('shelf_life_days')->nullable();
            $table->integer('min_remaining_shelf_life_days')->nullable();
            $table->decimal('net_weight_kg', 10, 3)->nullable();
            $table->decimal('volume_m3', 10, 5)->nullable();
            $table->integer('units_per_carton')->nullable();
            $table->integer('cartons_per_pallet')->nullable();
            $table->decimal('carton_tare_kg', 10, 3)->nullable();
            $table->decimal('pallet_tare_kg', 10, 3)->nullable();

            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
        });

        Schema::table('warehouses', function (Blueprint $table): void {
            $table->string('picking_strategy', 10)->nullable();
            $table->boolean('is_virtual')->default(false);
            $table->uuid('owner_contact_id')->nullable()->index();
            $table->string('city', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 2)->nullable();

            $table->foreign('owner_contact_id')->references('id')->on('contacts')->nullOnDelete();
        });

        Schema::table('warehouse_bins', function (Blueprint $table): void {
            $table->integer('pick_sequence')->nullable();
        });

        Schema::table('stock_lots', function (Blueprint $table): void {
            $table->string('lot_level', 20)->default('raw_material');
            $table->date('received_at')->nullable();
            $table->string('certification_scheme', 100)->nullable();
            $table->string('certification_reference', 150)->nullable()->index();
            $table->date('certification_date')->nullable();
        });

        Schema::create('stock_lot_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('lot_id');
            $table->uuid('component_lot_id');
            $table->decimal('quantity_consumed', 14, 4)->nullable();
            $table->uuid('production_order_id')->nullable()->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('lot_id')->references('id')->on('stock_lots')->cascadeOnDelete();
            $table->foreign('component_lot_id')->references('id')->on('stock_lots')->restrictOnDelete();
            $table->unique(['lot_id', 'component_lot_id', 'production_order_id'], 'uq_stock_lot_link');
            $table->index('component_lot_id');
        });

        Schema::create('stock_reservation_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('stock_reservation_id')->index();
            $table->uuid('stock_quant_id')->index();
            $table->uuid('lot_id')->nullable()->index();
            $table->uuid('warehouse_bin_id');
            $table->decimal('quantity', 14, 4);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('stock_reservation_id')->references('id')->on('stock_reservations')->cascadeOnDelete();
            $table->foreign('stock_quant_id')->references('id')->on('stock_quants')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservation_allocations');
        Schema::dropIfExists('stock_lot_links');

        Schema::table('stock_lots', function (Blueprint $table): void {
            $table->dropColumn(['lot_level', 'received_at', 'certification_scheme', 'certification_reference', 'certification_date']);
        });

        Schema::table('warehouse_bins', function (Blueprint $table): void {
            $table->dropColumn('pick_sequence');
        });

        Schema::table('warehouses', function (Blueprint $table): void {
            $table->dropForeign(['owner_contact_id']);
            $table->dropColumn(['picking_strategy', 'is_virtual', 'owner_contact_id', 'city', 'postal_code', 'country']);
        });

        Schema::table('stock_items', function (Blueprint $table): void {
            $table->dropForeign(['product_id']);
            $table->dropColumn(['product_id', 'picking_strategy', 'shelf_life_days', 'min_remaining_shelf_life_days', 'net_weight_kg', 'volume_m3', 'units_per_carton', 'cartons_per_pallet', 'carton_tare_kg', 'pallet_tare_kg']);
        });
    }
};
