<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Route order and reservation links on pick lines, packages inside packing lists, the log that makes terminal scans
     * safe to resend, and the effective date of retroactive stocktakes.
     */
    public function up(): void
    {
        Schema::table('stock_pick_lists', function (Blueprint $table): void {
            $table->jsonb('shortages')->nullable();
            $table->timestamp('completed_at')->nullable();
        });

        Schema::table('stock_pick_lines', function (Blueprint $table): void {
            $table->integer('sequence_number')->default(0);
            $table->uuid('stock_reservation_id')->nullable()->index();
            $table->uuid('picked_by_user_id')->nullable();

            $table->foreign('stock_reservation_id')->references('id')->on('stock_reservations')->nullOnDelete();
        });

        Schema::table('stock_packing_lists', function (Blueprint $table): void {
            $table->uuid('delivery_note_id')->nullable()->index();
            $table->decimal('net_weight_kg', 12, 3)->nullable();
            $table->decimal('total_volume_m3', 12, 5)->nullable();
            $table->integer('cartons_count')->default(0);
            $table->integer('pallets_count')->default(0);

            $table->foreign('delivery_note_id')->references('id')->on('delivery_notes')->nullOnDelete();
        });

        Schema::table('delivery_notes', function (Blueprint $table): void {
            $table->decimal('net_weight_kg', 12, 3)->nullable();
        });

        Schema::create('stock_packages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('stock_packing_list_id')->index();
            $table->uuid('parent_package_id')->nullable()->index();
            $table->string('package_number', 100);
            $table->string('package_type', 20)->default('carton');
            $table->decimal('net_weight_kg', 12, 3)->default(0);
            $table->decimal('tare_weight_kg', 12, 3)->default(0);
            $table->decimal('gross_weight_kg', 12, 3)->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('stock_packing_list_id')->references('id')->on('stock_packing_lists')->cascadeOnDelete();
            $table->unique(['tenant_id', 'package_number'], 'uq_stock_package_number');
        });

        Schema::table('stock_packages', function (Blueprint $table): void {
            $table->foreign('parent_package_id')->references('id')->on('stock_packages')->nullOnDelete();
        });

        Schema::create('stock_package_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('stock_package_id')->index();
            $table->uuid('stock_item_id');
            $table->uuid('lot_id')->nullable();
            $table->decimal('quantity', 14, 4);
            $table->decimal('net_weight_kg', 12, 3)->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('stock_package_id')->references('id')->on('stock_packages')->cascadeOnDelete();
            $table->foreign('stock_item_id')->references('id')->on('stock_items')->restrictOnDelete();
            $table->foreign('lot_id')->references('id')->on('stock_lots')->nullOnDelete();
        });

        Schema::create('stock_terminal_scans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('device_id', 100);
            $table->string('client_scan_id', 100);
            $table->string('operation', 30);
            $table->uuid('user_id')->nullable();
            $table->jsonb('payload');
            $table->jsonb('result')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'device_id', 'client_scan_id'], 'uq_terminal_scan');
        });

        Schema::table('inventory_count_sessions', function (Blueprint $table): void {
            $table->timestamp('effective_at')->nullable();
            $table->uuid('warehouse_zone_id')->nullable();
            $table->text('notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_count_sessions', function (Blueprint $table): void {
            $table->dropColumn(['effective_at', 'warehouse_zone_id', 'notes']);
        });

        Schema::dropIfExists('stock_terminal_scans');
        Schema::dropIfExists('stock_package_items');
        Schema::dropIfExists('stock_packages');

        Schema::table('delivery_notes', function (Blueprint $table): void {
            $table->dropColumn('net_weight_kg');
        });

        Schema::table('stock_packing_lists', function (Blueprint $table): void {
            $table->dropForeign(['delivery_note_id']);
            $table->dropColumn(['delivery_note_id', 'net_weight_kg', 'total_volume_m3', 'cartons_count', 'pallets_count']);
        });

        Schema::table('stock_pick_lines', function (Blueprint $table): void {
            $table->dropForeign(['stock_reservation_id']);
            $table->dropColumn(['sequence_number', 'stock_reservation_id', 'picked_by_user_id']);
        });

        Schema::table('stock_pick_lists', function (Blueprint $table): void {
            $table->dropColumn(['shortages', 'completed_at']);
        });
    }
};
