<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bills_of_materials', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('finished_product_id')->constrained('products')->cascadeOnDelete();
            $table->string('version', 20)->default('1.0');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('bom_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('bill_of_materials_id')->constrained('bills_of_materials')->cascadeOnDelete();
            $table->foreignUuid('raw_product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('quantity', 12, 4);
            $table->decimal('scrap_percentage', 5, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('production_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('bill_of_materials_id')->constrained('bills_of_materials')->cascadeOnDelete();
            $table->string('order_number', 50)->index();
            $table->decimal('target_quantity', 12, 4);
            $table->decimal('produced_quantity', 12, 4)->default(0);
            $table->date('start_date');
            $table->date('due_date')->nullable();
            $table->string('status', 30)->default('planned')->index(); // planned, in_progress, completed, cancelled
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'order_number']);
        });

        Schema::create('production_order_stages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->string('stage_name', 100); // e.g. Cutting, Assembling, Quality Check, Packing
            $table->unsignedInteger('sequence')->default(1);
            $table->decimal('planned_hours', 6, 2)->default(0);
            $table->decimal('actual_hours', 6, 2)->default(0);
            $table->string('status', 30)->default('pending')->index(); // pending, running, done
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_order_stages');
        Schema::dropIfExists('production_orders');
        Schema::dropIfExists('bom_items');
        Schema::dropIfExists('bills_of_materials');
    }
};
