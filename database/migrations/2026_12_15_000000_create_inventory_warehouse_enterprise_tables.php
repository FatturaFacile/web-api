<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Evolve existing Warehouses table
        Schema::table('warehouses', function (Blueprint $table): void {
            if (! Schema::hasColumn('warehouses', 'company_id')) {
                $table->uuid('company_id')->nullable()->index();
            }
            if (! Schema::hasColumn('warehouses', 'warehouse_type')) {
                $table->string('warehouse_type', 50)->default('standard'); // standard, transit, vehicle_van, consignment
            }
            if (! Schema::hasColumn('warehouses', 'assigned_user_id')) {
                $table->uuid('assigned_user_id')->nullable()->index();
            }
            if (! Schema::hasColumn('warehouses', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
        });

        // 2. Warehouse Zones
        if (! Schema::hasTable('warehouse_zones')) {
            Schema::create('warehouse_zones', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('warehouse_id');
                $table->string('code', 50);
                $table->string('name', 100);
                $table->string('zone_type', 50)->default('storage'); // receiving, storage, picking, packing, shipping, quarantine
                $table->timestamps();

                $table->foreign('warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();
                $table->unique(['warehouse_id', 'code'], 'uq_warehouse_zone');
            });
        }

        // 3. Warehouse Aisles
        if (! Schema::hasTable('warehouse_aisles')) {
            Schema::create('warehouse_aisles', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('warehouse_zone_id');
                $table->string('code', 50);
                $table->timestamps();

                $table->foreign('warehouse_zone_id')->references('id')->on('warehouse_zones')->cascadeOnDelete();
                $table->unique(['warehouse_zone_id', 'code'], 'uq_zone_aisle');
            });
        }

        // 4. Warehouse Racks
        if (! Schema::hasTable('warehouse_racks')) {
            Schema::create('warehouse_racks', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('warehouse_aisle_id');
                $table->string('code', 50);
                $table->timestamps();

                $table->foreign('warehouse_aisle_id')->references('id')->on('warehouse_aisles')->cascadeOnDelete();
                $table->unique(['warehouse_aisle_id', 'code'], 'uq_aisle_rack');
            });
        }

        // 5. Warehouse Bins (Granular storage location)
        if (! Schema::hasTable('warehouse_bins')) {
            Schema::create('warehouse_bins', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('warehouse_rack_id');
                $table->string('code', 50);
                $table->string('barcode', 100)->index();
                $table->decimal('max_weight_kg', 10, 2)->nullable();
                $table->decimal('max_volume_cbm', 10, 4)->nullable();
                $table->boolean('is_locked')->default(false);
                $table->timestamps();

                $table->foreign('warehouse_rack_id')->references('id')->on('warehouse_racks')->cascadeOnDelete();
                $table->unique(['tenant_id', 'barcode'], 'uq_bin_barcode');
            });
        }

        // 6. Stock Item Categories
        if (! Schema::hasTable('stock_item_categories')) {
            Schema::create('stock_item_categories', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('parent_category_id')->nullable();
                $table->string('code', 50);
                $table->string('name', 150);
                $table->string('valuation_method', 50)->default('fifo'); // fifo, moving_average, standard_cost
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code'], 'uq_item_category');
            });

            Schema::table('stock_item_categories', function (Blueprint $table): void {
                $table->foreign('parent_category_id')->references('id')->on('stock_item_categories')->nullOnDelete();
            });
        }

        // 7. Stock Items (Master inventory product)
        if (! Schema::hasTable('stock_items')) {
            Schema::create('stock_items', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('category_id');
                $table->string('sku', 100)->index();
                $table->string('barcode_ean13', 13)->nullable()->index();
                $table->string('barcode_custom', 100)->nullable()->index();
                $table->string('name', 255);
                $table->text('description')->nullable();
                $table->string('uom', 20)->default('PCE');
                $table->string('tracking_type', 50)->default('none'); // none, lot, serial
                $table->decimal('standard_cost', 14, 4)->default(0);
                $table->decimal('current_moving_average_cost', 14, 4)->default(0);
                $table->decimal('weight_kg', 10, 3)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('category_id')->references('id')->on('stock_item_categories')->restrictOnDelete();
                $table->unique(['tenant_id', 'sku'], 'uq_stock_item_sku');
            });
        }

        // 8. Stock Lots (Batch control)
        if (! Schema::hasTable('stock_lots')) {
            Schema::create('stock_lots', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('stock_item_id');
                $table->string('lot_number', 100);
                $table->string('supplier_lot_number', 100)->nullable();
                $table->uuid('supplier_id')->nullable();
                $table->date('manufacturing_date')->nullable();
                $table->date('expiration_date')->nullable()->index();
                $table->boolean('is_quarantined')->default(false);
                $table->timestamps();

                $table->foreign('stock_item_id')->references('id')->on('stock_items')->cascadeOnDelete();
                $table->unique(['tenant_id', 'stock_item_id', 'lot_number'], 'uq_stock_lot');
            });
        }

        // 9. Stock Serial Numbers
        if (! Schema::hasTable('stock_serial_numbers')) {
            Schema::create('stock_serial_numbers', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('stock_item_id');
                $table->string('serial_number', 100);
                $table->string('status', 50)->default('in_stock'); // in_stock, reserved, delivered, installed, scrapped
                $table->uuid('current_warehouse_bin_id')->nullable();
                $table->uuid('lot_id')->nullable();
                $table->date('warranty_start_date')->nullable();
                $table->date('warranty_end_date')->nullable();
                $table->uuid('customer_asset_id')->nullable();
                $table->timestamps();

                $table->foreign('stock_item_id')->references('id')->on('stock_items')->cascadeOnDelete();
                $table->foreign('current_warehouse_bin_id')->references('id')->on('warehouse_bins')->nullOnDelete();
                $table->foreign('lot_id')->references('id')->on('stock_lots')->nullOnDelete();
                $table->unique(['tenant_id', 'stock_item_id', 'serial_number'], 'uq_stock_serial');
                $table->index(['tenant_id', 'serial_number', 'status'], 'idx_serial_status');
            });
        }

        // 10. Stock Quants (On-hand point-in-time balance)
        if (! Schema::hasTable('stock_quants')) {
            Schema::create('stock_quants', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('warehouse_bin_id');
                $table->uuid('stock_item_id');
                $table->uuid('lot_id')->nullable();
                $table->decimal('quantity_on_hand', 14, 4)->default(0);
                $table->decimal('quantity_reserved', 14, 4)->default(0);
                $table->timestamps();

                $table->foreign('warehouse_bin_id')->references('id')->on('warehouse_bins')->restrictOnDelete();
                $table->foreign('stock_item_id')->references('id')->on('stock_items')->cascadeOnDelete();
                $table->foreign('lot_id')->references('id')->on('stock_lots')->nullOnDelete();
                $table->unique(['warehouse_bin_id', 'stock_item_id', 'lot_id'], 'uq_quant_slot');
                $table->index(['tenant_id', 'stock_item_id', 'quantity_on_hand'], 'idx_quants_item');
            });

            if (DB::getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE stock_quants ADD CONSTRAINT chk_positive_on_hand CHECK (quantity_on_hand >= 0.0000)');
                DB::statement('ALTER TABLE stock_quants ADD CONSTRAINT chk_reserved_lte_on_hand CHECK (quantity_reserved <= quantity_on_hand)');
            }
        }

        // 11. Evolve existing Stock Movements table for Double-Entry Ledger
        Schema::table('stock_movements', function (Blueprint $table): void {
            if (! Schema::hasColumn('stock_movements', 'stock_item_id')) {
                $table->uuid('stock_item_id')->nullable()->index();
            }
            if (! Schema::hasColumn('stock_movements', 'source_location_id')) {
                $table->uuid('source_location_id')->nullable()->index();
            }
            if (! Schema::hasColumn('stock_movements', 'destination_location_id')) {
                $table->uuid('destination_location_id')->nullable()->index();
            }
            if (! Schema::hasColumn('stock_movements', 'lot_id')) {
                $table->uuid('lot_id')->nullable()->index();
            }
            if (! Schema::hasColumn('stock_movements', 'serial_number_id')) {
                $table->uuid('serial_number_id')->nullable()->index();
            }
            if (! Schema::hasColumn('stock_movements', 'uom')) {
                $table->string('uom', 20)->default('PCE');
            }
            if (! Schema::hasColumn('stock_movements', 'total_valuation')) {
                $table->decimal('total_valuation', 14, 2)->default(0);
            }
            if (! Schema::hasColumn('stock_movements', 'movement_type')) {
                $table->string('movement_type', 50)->default('internal_transfer')->index();
            }
            if (! Schema::hasColumn('stock_movements', 'origin_document_type')) {
                $table->string('origin_document_type', 255)->nullable();
            }
            if (! Schema::hasColumn('stock_movements', 'origin_document_id')) {
                $table->uuid('origin_document_id')->nullable();
            }
            if (! Schema::hasColumn('stock_movements', 'executed_by_user_id')) {
                $table->uuid('executed_by_user_id')->nullable()->index();
            }
            if (! Schema::hasColumn('stock_movements', 'executed_at')) {
                $table->timestamp('executed_at')->useCurrent()->index();
            }
            if (! Schema::hasColumn('stock_movements', 'company_id')) {
                $table->uuid('company_id')->nullable()->index();
            }
        });

        // 12. Stock Reservations
        if (! Schema::hasTable('stock_reservations')) {
            Schema::create('stock_reservations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('stock_item_id');
                $table->uuid('warehouse_id');
                $table->string('reserved_for_type', 255);
                $table->uuid('reserved_for_id');
                $table->decimal('quantity_reserved', 14, 4);
                $table->string('status', 50)->default('active'); // active, picked, released, cancelled
                $table->timestamp('expires_at')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestamps();

                $table->foreign('stock_item_id')->references('id')->on('stock_items')->cascadeOnDelete();
                $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
                $table->index(['tenant_id', 'stock_item_id', 'status'], 'idx_reservations_alloc');
                $table->index(['reserved_for_type', 'reserved_for_id'], 'idx_reservations_target');
            });
        }

        // 13. Stock Pick Lists
        if (! Schema::hasTable('stock_pick_lists')) {
            Schema::create('stock_pick_lists', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('warehouse_id');
                $table->string('pick_list_number', 100);
                $table->uuid('assigned_picker_user_id')->nullable();
                $table->string('status', 50)->default('draft'); // draft, assigned, picking, completed, cancelled
                $table->string('origin_document_type', 255)->nullable();
                $table->uuid('origin_document_id')->nullable();
                $table->timestamps();

                $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
                $table->unique(['tenant_id', 'pick_list_number'], 'uq_pick_list_num');
            });
        }

        // 14. Stock Pick Lines
        if (! Schema::hasTable('stock_pick_lines')) {
            Schema::create('stock_pick_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('stock_pick_list_id');
                $table->uuid('stock_item_id');
                $table->uuid('warehouse_bin_id');
                $table->uuid('lot_id')->nullable();
                $table->decimal('quantity_to_pick', 14, 4);
                $table->decimal('quantity_picked', 14, 4)->default(0);
                $table->string('status', 50)->default('pending'); // pending, picked, shorted
                $table->timestamp('picked_at')->nullable();
                $table->timestamps();

                $table->foreign('stock_pick_list_id')->references('id')->on('stock_pick_lists')->cascadeOnDelete();
                $table->foreign('stock_item_id')->references('id')->on('stock_items')->restrictOnDelete();
                $table->foreign('warehouse_bin_id')->references('id')->on('warehouse_bins')->restrictOnDelete();
                $table->foreign('lot_id')->references('id')->on('stock_lots')->nullOnDelete();
            });
        }

        // 15. Stock Packing Lists
        if (! Schema::hasTable('stock_packing_lists')) {
            Schema::create('stock_packing_lists', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('stock_pick_list_id');
                $table->string('packing_number', 100);
                $table->integer('total_packages')->default(1);
                $table->decimal('total_weight_kg', 10, 3)->nullable();
                $table->string('shipping_carrier', 100)->nullable();
                $table->string('tracking_number', 150)->nullable();
                $table->uuid('packed_by_user_id')->nullable();
                $table->timestamp('packed_at')->useCurrent();
                $table->timestamps();

                $table->foreign('stock_pick_list_id')->references('id')->on('stock_pick_lists')->cascadeOnDelete();
                $table->unique(['tenant_id', 'packing_number'], 'uq_packing_num');
            });
        }

        // 16. Stock Reorder Rules
        if (! Schema::hasTable('stock_reorder_rules')) {
            Schema::create('stock_reorder_rules', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('warehouse_id');
                $table->uuid('stock_item_id');
                $table->decimal('min_stock_level', 14, 4);
                $table->decimal('max_stock_level', 14, 4);
                $table->decimal('reorder_point', 14, 4);
                $table->decimal('economic_order_quantity', 14, 4);
                $table->integer('lead_time_days')->default(7);
                $table->uuid('preferred_supplier_id')->nullable();
                $table->boolean('auto_trigger_requisition')->default(true);
                $table->timestamps();

                $table->foreign('warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();
                $table->foreign('stock_item_id')->references('id')->on('stock_items')->cascadeOnDelete();
                $table->unique(['warehouse_id', 'stock_item_id'], 'uq_item_reorder_rule');
            });
        }

        // 17. Inventory Adjustments
        if (! Schema::hasTable('inventory_adjustments')) {
            Schema::create('inventory_adjustments', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('warehouse_id');
                $table->string('adjustment_number', 100);
                $table->string('reason', 100); // cycle_count_variance, damage_loss, obsolete_write_off, found_stock
                $table->string('status', 50)->default('draft'); // draft, pending_approval, approved, rejected
                $table->decimal('net_valuation_change', 14, 2)->default(0);
                $table->boolean('is_locked')->default(false);
                $table->uuid('created_by');
                $table->uuid('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();

                $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
                $table->unique(['tenant_id', 'adjustment_number'], 'uq_inv_adj_num');
            });
        }

        // 18. Inventory Count Sessions
        if (! Schema::hasTable('inventory_count_sessions')) {
            Schema::create('inventory_count_sessions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('warehouse_id');
                $table->string('session_number', 100);
                $table->string('count_type', 50)->default('cycle'); // cycle, full_annual, spot_check
                $table->string('status', 50)->default('in_progress'); // in_progress, completed, reconciled, cancelled
                $table->date('scheduled_date');
                $table->boolean('is_locked')->default(false);
                $table->timestamp('completed_at')->nullable();
                $table->uuid('created_by');
                $table->timestamps();

                $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
                $table->unique(['tenant_id', 'session_number'], 'uq_inv_count_session');
            });
        }

        // 19. Inventory Count Lines
        if (! Schema::hasTable('inventory_count_lines')) {
            Schema::create('inventory_count_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('inventory_count_session_id');
                $table->uuid('warehouse_bin_id');
                $table->uuid('stock_item_id');
                $table->uuid('lot_id')->nullable();
                $table->decimal('system_quantity', 14, 4);
                $table->decimal('counted_quantity', 14, 4)->nullable();
                $table->decimal('discrepancy_quantity', 14, 4)->default(0);
                $table->decimal('discrepancy_valuation', 14, 2)->default(0);
                $table->uuid('counted_by_user_id')->nullable();
                $table->timestamp('counted_at')->nullable();
                $table->timestamps();

                $table->foreign('inventory_count_session_id')->references('id')->on('inventory_count_sessions')->cascadeOnDelete();
                $table->foreign('warehouse_bin_id')->references('id')->on('warehouse_bins')->restrictOnDelete();
                $table->foreign('stock_item_id')->references('id')->on('stock_items')->restrictOnDelete();
                $table->foreign('lot_id')->references('id')->on('stock_lots')->nullOnDelete();
            });
        }

        // 20. Stock Valuation Snapshots
        if (! Schema::hasTable('stock_valuation_snapshots')) {
            Schema::create('stock_valuation_snapshots', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->index();
                $table->uuid('warehouse_id');
                $table->uuid('stock_item_id');
                $table->date('snapshot_date');
                $table->string('valuation_method', 50);
                $table->decimal('quantity_on_hand', 14, 4);
                $table->decimal('unit_cost', 14, 4);
                $table->decimal('total_valuation', 14, 2);
                $table->timestamps();

                $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
                $table->foreign('stock_item_id')->references('id')->on('stock_items')->restrictOnDelete();
                $table->unique(['company_id', 'warehouse_id', 'stock_item_id', 'snapshot_date'], 'uq_val_snapshot');
            });
        }

        // 21. Inventory Audit Entries
        if (! Schema::hasTable('inventory_audit_entries')) {
            Schema::create('inventory_audit_entries', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('stock_item_id');
                $table->uuid('movement_id')->nullable()->index();
                $table->uuid('actor_user_id')->index();
                $table->string('event_type', 100);
                $table->decimal('quantity_change', 14, 4);
                $table->decimal('valuation_impact', 14, 2)->default(0);
                $table->string('source_location_desc', 255)->nullable();
                $table->string('dest_location_desc', 255)->nullable();
                $table->string('reason_code', 100)->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->timestamps();

                $table->foreign('stock_item_id')->references('id')->on('stock_items')->cascadeOnDelete();
                $table->index(['tenant_id', 'stock_item_id', 'created_at'], 'idx_inv_audit_item');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_audit_entries');
        Schema::dropIfExists('stock_valuation_snapshots');
        Schema::dropIfExists('inventory_count_lines');
        Schema::dropIfExists('inventory_count_sessions');
        Schema::dropIfExists('inventory_adjustments');
        Schema::dropIfExists('stock_reorder_rules');
        Schema::dropIfExists('stock_packing_lists');
        Schema::dropIfExists('stock_pick_lines');
        Schema::dropIfExists('stock_pick_lists');
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('stock_quants');
        Schema::dropIfExists('stock_serial_numbers');
        Schema::dropIfExists('stock_lots');
        Schema::dropIfExists('stock_items');
        Schema::dropIfExists('stock_item_categories');
        Schema::dropIfExists('warehouse_bins');
        Schema::dropIfExists('warehouse_racks');
        Schema::dropIfExists('warehouse_aisles');
        Schema::dropIfExists('warehouse_zones');
    }
};
