<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Warehouse management on top of the single stock ledger: bin types and capacities, putaway rules, ABC classes and
     * cycle count plans, stock freezes during physical counts, recounts and the approval of count differences, pick
     * waves and shipment confirmation, and transfer orders between warehouses with their stock in transit.
     *
     * Additive only: every new column is nullable or defaulted, so existing rows keep their meaning (a bin without a
     * type is a storage bin, a count session without freeze does not block movements).
     */
    public function up(): void
    {
        Schema::table('warehouse_bins', function (Blueprint $table): void {
            if (! Schema::hasColumn('warehouse_bins', 'bin_type')) {
                $table->string('bin_type', 30)->default('storage');
            }
            if (! Schema::hasColumn('warehouse_bins', 'max_quantity')) {
                $table->decimal('max_quantity', 14, 4)->nullable();
            }
        });

        Schema::table('stock_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('stock_items', 'abc_class')) {
                $table->string('abc_class', 1)->nullable()->index();
                $table->timestamp('abc_classified_at')->nullable();
            }
        });

        if (! Schema::hasTable('putaway_rules')) {
            Schema::create('putaway_rules', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('warehouse_id');
                $table->uuid('stock_item_id')->nullable();
                $table->uuid('category_id')->nullable();
                $table->uuid('target_zone_id')->nullable();
                $table->uuid('target_bin_id')->nullable();
                $table->string('bin_type', 30)->nullable();
                $table->string('strategy', 30)->default('fixed_location');
                $table->integer('priority')->default(100);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();
                $table->foreign('stock_item_id')->references('id')->on('stock_items')->cascadeOnDelete();
                $table->foreign('category_id')->references('id')->on('stock_item_categories')->cascadeOnDelete();
                $table->foreign('target_zone_id')->references('id')->on('warehouse_zones')->nullOnDelete();
                $table->foreign('target_bin_id')->references('id')->on('warehouse_bins')->nullOnDelete();
                $table->index(['tenant_id', 'warehouse_id', 'is_active', 'priority'], 'idx_putaway_rules_lookup');
            });
        }

        if (! Schema::hasTable('cycle_count_plans')) {
            Schema::create('cycle_count_plans', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('warehouse_id');
                $table->string('name', 150);
                $table->string('abc_class', 1)->nullable();
                $table->integer('interval_days');
                $table->boolean('freezes_stock')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_run_at')->nullable();
                $table->date('next_run_on')->nullable()->index();
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();
            });
        }

        Schema::table('inventory_count_sessions', function (Blueprint $table): void {
            if (! Schema::hasColumn('inventory_count_sessions', 'freezes_stock')) {
                $table->boolean('freezes_stock')->default(false);
                $table->timestamp('frozen_at')->nullable();
                $table->uuid('cycle_count_plan_id')->nullable()->index();
                $table->uuid('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->uuid('inventory_adjustment_id')->nullable();
                $table->index(['tenant_id', 'warehouse_id', 'freezes_stock', 'status'], 'idx_count_sessions_freeze');
            }
        });

        Schema::table('inventory_count_lines', function (Blueprint $table): void {
            if (! Schema::hasColumn('inventory_count_lines', 'first_counted_quantity')) {
                $table->decimal('first_counted_quantity', 14, 4)->nullable();
                $table->integer('recount_count')->default(0);
                $table->timestamp('recount_requested_at')->nullable();
            }
        });

        if (! Schema::hasTable('pick_waves')) {
            Schema::create('pick_waves', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('warehouse_id');
                $table->string('wave_number', 100);
                $table->string('status', 30)->default('released');
                $table->uuid('staging_bin_id')->nullable();
                $table->uuid('assigned_picker_user_id')->nullable();
                $table->jsonb('shortages')->nullable();
                $table->text('notes')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestamp('released_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
                $table->foreign('staging_bin_id')->references('id')->on('warehouse_bins')->nullOnDelete();
                $table->unique(['tenant_id', 'wave_number'], 'uq_pick_wave_number');
            });
        }

        Schema::table('stock_pick_lists', function (Blueprint $table): void {
            if (! Schema::hasColumn('stock_pick_lists', 'pick_wave_id')) {
                $table->uuid('pick_wave_id')->nullable()->index();
                $table->uuid('delivery_note_id')->nullable()->index();
                $table->timestamp('shipped_at')->nullable();

                $table->foreign('pick_wave_id')->references('id')->on('pick_waves')->nullOnDelete();
                $table->foreign('delivery_note_id')->references('id')->on('delivery_notes')->nullOnDelete();
            }
        });

        Schema::table('stock_pick_lines', function (Blueprint $table): void {
            if (! Schema::hasColumn('stock_pick_lines', 'destination_bin_id')) {
                $table->uuid('destination_bin_id')->nullable();

                $table->foreign('destination_bin_id')->references('id')->on('warehouse_bins')->nullOnDelete();
            }
        });

        if (! Schema::hasTable('transfer_orders')) {
            Schema::create('transfer_orders', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->string('transfer_number', 100);
                $table->uuid('source_warehouse_id');
                $table->uuid('destination_warehouse_id');
                $table->uuid('transit_bin_id')->nullable();
                $table->string('status', 30)->default('draft');
                $table->date('requested_date')->nullable();
                $table->timestamp('shipped_at')->nullable();
                $table->timestamp('received_at')->nullable();
                $table->uuid('created_by')->nullable();
                $table->uuid('shipped_by')->nullable();
                $table->uuid('received_by')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('source_warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
                $table->foreign('destination_warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
                $table->foreign('transit_bin_id')->references('id')->on('warehouse_bins')->restrictOnDelete();
                $table->unique(['tenant_id', 'transfer_number'], 'uq_transfer_order_number');
                $table->index(['tenant_id', 'status'], 'idx_transfer_orders_status');
            });
        }

        if (! Schema::hasTable('transfer_order_lines')) {
            Schema::create('transfer_order_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('transfer_order_id')->index();
                $table->uuid('stock_item_id');
                $table->uuid('lot_id')->nullable();
                $table->decimal('quantity', 14, 4);
                $table->decimal('quantity_shipped', 14, 4)->default(0);
                $table->decimal('quantity_received', 14, 4)->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('transfer_order_id')->references('id')->on('transfer_orders')->cascadeOnDelete();
                $table->foreign('stock_item_id')->references('id')->on('stock_items')->restrictOnDelete();
                $table->foreign('lot_id')->references('id')->on('stock_lots')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('transfer_order_line_lots')) {
            Schema::create('transfer_order_line_lots', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('transfer_order_line_id')->index();
                $table->uuid('lot_id')->nullable();
                $table->decimal('quantity_shipped', 14, 4);
                $table->decimal('quantity_received', 14, 4)->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('transfer_order_line_id')->references('id')->on('transfer_order_lines')->cascadeOnDelete();
                $table->foreign('lot_id')->references('id')->on('stock_lots')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_order_line_lots');
        Schema::dropIfExists('transfer_order_lines');
        Schema::dropIfExists('transfer_orders');

        Schema::table('stock_pick_lines', function (Blueprint $table): void {
            $table->dropForeign(['destination_bin_id']);
            $table->dropColumn('destination_bin_id');
        });

        Schema::table('stock_pick_lists', function (Blueprint $table): void {
            $table->dropForeign(['pick_wave_id']);
            $table->dropForeign(['delivery_note_id']);
            $table->dropColumn(['pick_wave_id', 'delivery_note_id', 'shipped_at']);
        });

        Schema::dropIfExists('pick_waves');

        Schema::table('inventory_count_lines', function (Blueprint $table): void {
            $table->dropColumn(['first_counted_quantity', 'recount_count', 'recount_requested_at']);
        });

        Schema::table('inventory_count_sessions', function (Blueprint $table): void {
            $table->dropIndex('idx_count_sessions_freeze');
            $table->dropColumn(['freezes_stock', 'frozen_at', 'cycle_count_plan_id', 'approved_by', 'approved_at', 'inventory_adjustment_id']);
        });

        Schema::dropIfExists('cycle_count_plans');
        Schema::dropIfExists('putaway_rules');

        Schema::table('stock_items', function (Blueprint $table): void {
            $table->dropColumn(['abc_class', 'abc_classified_at']);
        });

        Schema::table('warehouse_bins', function (Blueprint $table): void {
            $table->dropColumn(['bin_type', 'max_quantity']);
        });
    }
};
