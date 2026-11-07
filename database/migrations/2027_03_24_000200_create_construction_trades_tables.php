<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Painters, plasterers and construction trades: price lists (own, or imported from a licensed catalogue such as the
 * CRB NPK), work cycles, bids with positions and budgets, measurements, partial and final billings, and site costs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ct_price_lists', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('source', 20)->default('own');
            $table->string('edition', 40)->nullable();
            $table->string('license_reference', 150)->nullable();
            $table->string('currency', 3)->default('CHF');
            $table->json('languages');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('ct_price_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('price_list_id')->constrained('ct_price_lists')->cascadeOnDelete();
            $table->string('position_code', 40);
            $table->string('parent_code', 40)->nullable();
            $table->string('unit', 10);
            $table->decimal('unit_price', 15, 4);
            $table->decimal('labour_minutes', 10, 2)->nullable();
            $table->decimal('material_cost', 15, 4)->nullable();
            $table->decimal('machine_cost', 15, 4)->nullable();
            $table->json('descriptions');
            $table->timestamps();

            $table->unique(['price_list_id', 'position_code']);
            $table->index(['tenant_id', 'price_list_id']);
        });

        Schema::create('ct_work_cycles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('unit', 10);
            $table->json('descriptions');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('ct_work_cycle_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('work_cycle_id')->constrained('ct_work_cycles')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignUuid('price_item_id')->nullable()->constrained('ct_price_items')->nullOnDelete();
            $table->json('descriptions');
            $table->decimal('quantity_factor', 12, 4)->default(1);
            $table->decimal('unit_price', 15, 4);
            $table->decimal('labour_minutes', 10, 2)->default(0);
            $table->decimal('material_cost', 15, 4)->default(0);
            $table->decimal('machine_cost', 15, 4)->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'work_cycle_id']);
        });

        Schema::create('ct_bids', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('construction_site_id')->nullable()->constrained('construction_sites')->nullOnDelete();
            $table->foreignUuid('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->string('bid_number', 40);
            $table->string('title', 200);
            $table->string('language', 2)->default('it');
            $table->string('currency', 3)->default('CHF');
            $table->decimal('tax_rate', 5, 2);
            $table->decimal('discount_percentage', 5, 2)->default(0);
            $table->decimal('retention_percentage', 5, 2)->default(0);
            $table->decimal('labour_hour_rate', 15, 2)->default(0);
            $table->string('status', 20)->default('draft');
            $table->date('valid_until')->nullable();
            $table->timestamp('settlement_approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'bid_number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('ct_bid_positions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('bid_id')->constrained('ct_bids')->cascadeOnDelete();
            $table->string('position_code', 40);
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('description');
            $table->string('unit', 10);
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_price', 15, 4);
            $table->decimal('total', 15, 2);
            $table->foreignUuid('price_item_id')->nullable()->constrained('ct_price_items')->nullOnDelete();
            $table->foreignUuid('work_cycle_id')->nullable()->constrained('ct_work_cycles')->nullOnDelete();
            $table->json('components')->nullable();
            $table->decimal('budget_labour_hours', 12, 2)->default(0);
            $table->decimal('budget_labour_amount', 15, 2)->default(0);
            $table->decimal('budget_material_amount', 15, 2)->default(0);
            $table->decimal('budget_machine_amount', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['bid_id', 'position_code']);
            $table->index(['tenant_id', 'bid_id']);
        });

        Schema::create('ct_measurements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('bid_id')->constrained('ct_bids')->cascadeOnDelete();
            $table->foreignUuid('bid_position_id')->constrained('ct_bid_positions')->cascadeOnDelete();
            $table->date('measured_on');
            $table->decimal('quantity', 15, 4);
            $table->string('note', 500)->nullable();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'bid_id']);
        });

        Schema::create('ct_billings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('bid_id')->constrained('ct_bids')->restrictOnDelete();
            $table->string('kind', 10);
            $table->string('basis', 12);
            $table->unsignedInteger('sequence');
            $table->date('billing_date');
            $table->decimal('percentage', 7, 4)->nullable();
            $table->decimal('gross_to_date', 15, 2);
            $table->decimal('previous_gross', 15, 2);
            $table->decimal('gross_this', 15, 2);
            $table->decimal('retention_amount', 15, 2)->default(0);
            $table->decimal('amount_invoiced', 15, 2);
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignUuid('progress_billing_id')->nullable()->constrained('progress_billings')->nullOnDelete();
            $table->timestamps();

            $table->unique(['bid_id', 'sequence']);
            $table->index(['tenant_id', 'bid_id']);
        });

        Schema::create('ct_site_costs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('bid_id')->constrained('ct_bids')->cascadeOnDelete();
            $table->foreignUuid('bid_position_id')->nullable()->constrained('ct_bid_positions')->nullOnDelete();
            $table->string('cost_type', 12);
            $table->date('cost_date');
            $table->foreignUuid('worker_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('description', 500);
            $table->decimal('quantity', 12, 4);
            $table->decimal('unit_cost', 15, 4);
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->index(['tenant_id', 'bid_id', 'cost_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ct_site_costs');
        Schema::dropIfExists('ct_billings');
        Schema::dropIfExists('ct_measurements');
        Schema::dropIfExists('ct_bid_positions');
        Schema::dropIfExists('ct_bids');
        Schema::dropIfExists('ct_work_cycle_steps');
        Schema::dropIfExists('ct_work_cycles');
        Schema::dropIfExists('ct_price_items');
        Schema::dropIfExists('ct_price_lists');
    }
};
