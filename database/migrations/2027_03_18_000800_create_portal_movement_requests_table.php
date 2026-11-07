<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a customer asks the warehouse to do with the stock it keeps there, and what the warehouse answered.
     */
    public function up(): void
    {
        Schema::create('portal_movement_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('contact_id')->index();
            $table->uuid('warehouse_id');
            $table->uuid('stock_item_id');
            $table->uuid('lot_id')->nullable();
            $table->string('request_type', 20);
            $table->decimal('quantity', 14, 4);
            $table->date('requested_date')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->uuid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->uuid('stock_reservation_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('contact_id')->references('id')->on('contacts')->cascadeOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->foreign('stock_item_id')->references('id')->on('stock_items')->restrictOnDelete();
            $table->foreign('lot_id')->references('id')->on('stock_lots')->nullOnDelete();
            $table->foreign('stock_reservation_id')->references('id')->on('stock_reservations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_movement_requests');
    }
};
