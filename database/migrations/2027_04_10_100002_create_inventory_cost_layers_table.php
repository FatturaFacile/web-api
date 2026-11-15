<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FatturaFacile/FatturaFacile#144 — persistent inventory cost layers (FIFO depletion and
 * landed cost uplift) and their consumptions (COGS traceability per issue movement).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_cost_layers')) {
            Schema::create('inventory_cost_layers', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('stock_item_id');
                $table->uuid('warehouse_id')->nullable()->index();
                $table->uuid('lot_id')->nullable()->index();
                $table->uuid('source_movement_id')->nullable()->index();
                $table->string('source_type', 50)->default('receipt'); // receipt, adjustment, opening_balance
                $table->uuid('receipt_id')->nullable()->index();
                $table->timestamp('received_at');
                $table->bigInteger('layer_sequence')->default(0); // FIFO tie-breaker within the same received_at
                $table->decimal('original_quantity', 14, 4);
                $table->decimal('remaining_quantity', 14, 4);
                $table->decimal('unit_cost', 14, 4);
                $table->decimal('landed_cost_unit', 14, 4)->default(0);
                $table->string('currency', 3)->default('EUR');
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('stock_item_id')->references('id')->on('stock_items')->restrictOnDelete();
                $table->index(['stock_item_id', 'received_at'], 'idx_cost_layers_fifo');
                $table->index(['tenant_id', 'stock_item_id', 'remaining_quantity'], 'idx_cost_layers_open');
            });
        }

        if (! Schema::hasTable('inventory_cost_layer_consumptions')) {
            Schema::create('inventory_cost_layer_consumptions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('layer_id');
                $table->uuid('stock_item_id')->index();
                $table->uuid('issue_movement_id')->index();
                $table->decimal('quantity', 14, 4);
                $table->decimal('unit_cost', 14, 4);
                $table->decimal('cogs_amount', 14, 2);
                $table->timestamp('consumed_at')->useCurrent();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('layer_id')->references('id')->on('inventory_cost_layers')->restrictOnDelete();
                $table->index(['layer_id', 'consumed_at'], 'idx_layer_consumptions');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_cost_layer_consumptions');
        Schema::dropIfExists('inventory_cost_layers');
    }
};
