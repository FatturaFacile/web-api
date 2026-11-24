<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant service on top of the POS: rooms and tables, orders by course, kitchen tickets per station, and the
 * payments of an order, each one a POS sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hosp_rooms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedSmallInteger('width')->default(100);
            $table->unsignedSmallInteger('height')->default(100);
            $table->timestamps();

            $table->index(['tenant_id', 'sort_order']);
        });

        Schema::create('hosp_tables', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('room_id')->constrained('hosp_rooms')->cascadeOnDelete();
            $table->string('name', 30);
            $table->unsignedSmallInteger('seats')->default(2);
            $table->string('shape', 10)->default('square');
            $table->unsignedSmallInteger('pos_x')->default(0);
            $table->unsignedSmallInteger('pos_y')->default(0);
            $table->unsignedSmallInteger('width')->default(10);
            $table->unsignedSmallInteger('height')->default(10);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['room_id', 'name']);
            $table->index(['tenant_id', 'room_id']);
        });

        Schema::create('hosp_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('table_id')->constrained('hosp_tables')->restrictOnDelete();
            $table->unsignedSmallInteger('covers')->default(1);
            $table->string('status', 10)->default('open');
            $table->foreignUuid('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'table_id', 'status']);
        });

        Schema::create('hosp_order_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('order_id')->constrained('hosp_orders')->cascadeOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('description', 255);
            $table->decimal('quantity', 12, 4);
            $table->decimal('unit_price', 15, 4);
            $table->decimal('tax_rate', 5, 2);
            $table->unsignedTinyInteger('course')->default(1);
            $table->string('station', 20)->default('kitchen');
            $table->string('notes', 255)->nullable();
            $table->string('status', 10)->default('pending');
            $table->decimal('paid_quantity', 12, 4)->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'order_id']);
        });

        Schema::create('hosp_kitchen_tickets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('order_id')->constrained('hosp_orders')->cascadeOnDelete();
            $table->string('station', 20);
            $table->unsignedTinyInteger('course');
            $table->string('status', 10)->default('open');
            $table->json('lines');
            $table->timestamp('ready_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'station', 'status']);
        });

        Schema::create('hosp_order_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('order_id')->constrained('hosp_orders')->restrictOnDelete();
            $table->foreignUuid('pos_sale_id')->constrained('pos_sales')->restrictOnDelete();
            $table->string('mode', 10);
            $table->decimal('amount', 15, 2);
            $table->json('lines');
            $table->timestamps();

            $table->index(['tenant_id', 'order_id']);
        });
    }

    public function down(): void
    {
        foreach (['hosp_order_payments', 'hosp_kitchen_tickets', 'hosp_order_items', 'hosp_orders', 'hosp_tables', 'hosp_rooms'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
