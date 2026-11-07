<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who bought each serial and for how much, on top of the warranty dates it already has, and the repair orders
     * opened on it.
     */
    public function up(): void
    {
        Schema::table('stock_serial_numbers', function (Blueprint $table): void {
            $table->uuid('contact_id')->nullable()->index();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_value', 15, 2)->nullable();
            $table->string('purchase_currency', 3)->nullable();
            $table->text('warranty_terms')->nullable();

            $table->foreign('contact_id')->references('id')->on('contacts')->nullOnDelete();
        });

        Schema::create('repair_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('repair_number', 50);
            $table->uuid('stock_serial_number_id')->index();
            $table->uuid('stock_item_id')->index();
            $table->uuid('contact_id')->index();
            $table->decimal('purchase_value', 15, 2)->nullable();
            $table->string('purchase_currency', 3)->nullable();
            $table->boolean('is_under_warranty')->default(false);
            $table->string('status', 20)->default('received')->index();
            $table->text('fault_description');
            $table->text('diagnosis')->nullable();
            $table->text('resolution')->nullable();
            $table->decimal('labour_amount', 15, 2)->default(0);
            $table->decimal('parts_amount', 15, 2)->default(0);
            $table->timestamp('received_at');
            $table->timestamp('delivered_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('stock_serial_number_id')->references('id')->on('stock_serial_numbers')->restrictOnDelete();
            $table->foreign('stock_item_id')->references('id')->on('stock_items')->restrictOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts')->restrictOnDelete();
            $table->unique(['tenant_id', 'repair_number'], 'uq_repair_number');
        });

        Schema::create('repair_order_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('repair_order_id')->index();
            $table->uuid('stock_item_id');
            $table->uuid('lot_id')->nullable();
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_cost', 15, 4);
            $table->decimal('line_total', 15, 2);
            $table->uuid('stock_movement_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('repair_order_id')->references('id')->on('repair_orders')->cascadeOnDelete();
            $table->foreign('stock_item_id')->references('id')->on('stock_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_order_lines');
        Schema::dropIfExists('repair_orders');

        Schema::table('stock_serial_numbers', function (Blueprint $table): void {
            $table->dropForeign(['contact_id']);
            $table->dropColumn(['contact_id', 'purchase_date', 'purchase_value', 'purchase_currency', 'warranty_terms']);
        });
    }
};
