<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GH-396: Depot repair RMA orders, diagnosis/estimate lines and status history (Module 17 ESM).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('esm_rma_counters')) {
            Schema::create('esm_rma_counters', function (Blueprint $table): void {
                $table->id();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->unsignedSmallInteger('year');
                $table->unsignedInteger('last_value')->default(0);
                $table->timestamps();

                $table->unique(['tenant_id', 'year'], 'esm_rma_counters_tenant_year_unique');
            });
        }

        if (! Schema::hasTable('esm_rma_orders')) {
            Schema::create('esm_rma_orders', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->string('rma_number', 30);
                $table->foreignUuid('equipment_passport_id')->constrained('esm_equipment_passports')->restrictOnDelete();
                $table->uuid('customer_id')->index(); // contacts (no FK)
                $table->string('status', 40)->default('awaiting_arrival')->index();

                // Warranty evaluation snapshot at RMA issue
                $table->string('warranty_status', 40)->index(); // covered_in_warranty, out_of_warranty_billable, goodwill
                $table->timestamp('warranty_evaluated_at')->nullable();
                $table->json('warranty_basis')->nullable();
                $table->text('reported_symptom');

                // Receipt
                $table->timestamp('received_at')->nullable();
                $table->text('receipt_condition_notes')->nullable();
                $table->json('receipt_photos')->nullable();

                // Bench diagnosis
                $table->text('lab_bench_diagnostic_notes')->nullable();
                $table->timestamp('diagnosed_at')->nullable();
                $table->uuid('diagnosed_by_user_id')->nullable();
                $table->boolean('parts_required')->default(false);

                // Estimate
                $table->uuid('repair_estimate_quote_id')->nullable()->index(); // Invoicing quote (no FK)
                $table->decimal('estimate_labour_total', 18, 2)->default(0.00);
                $table->decimal('estimate_parts_total', 18, 2)->default(0.00);
                $table->decimal('estimate_total', 18, 2)->default(0.00);
                $table->string('currency', 3)->default('EUR');
                $table->decimal('preauthorized_threshold', 18, 2)->default(0.00);
                $table->string('estimate_approval_status', 40)->default('not_required');
                $table->string('estimate_token_hash', 64)->nullable()->index();
                $table->timestamp('estimate_token_expires_at')->nullable();
                $table->timestamp('estimate_decided_at')->nullable();
                $table->string('estimate_decided_via', 20)->nullable(); // public_link, staff, auto
                $table->string('estimate_decided_by_name', 150)->nullable();
                $table->string('estimate_decision_ip', 45)->nullable();
                $table->text('estimate_decision_note')->nullable();

                // Public tracking
                $table->string('tracking_token_hash', 64)->nullable()->index();

                // Loaner & logistics/fiscal flags (no document generation)
                $table->uuid('loaner_id')->nullable()->index();
                $table->string('ddt_causale_outbound', 40)->nullable();
                $table->string('ddt_causale_return', 40)->nullable();
                $table->boolean('is_non_ownership_transfer')->default(true);
                $table->boolean('vat_exempt_art15_dpr633')->default(false);

                // Outcome
                $table->string('repair_outcome', 30)->nullable(); // repaired, returned_unrepaired, replaced
                $table->text('repair_test_notes')->nullable();
                $table->timestamp('returned_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->text('cancellation_reason')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'rma_number'], 'esm_rma_orders_tenant_number_unique');
            });
        }

        if (! Schema::hasTable('esm_rma_lines')) {
            Schema::create('esm_rma_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('rma_order_id')->constrained('esm_rma_orders')->cascadeOnDelete();
                $table->string('line_type', 20); // part, labour
                $table->uuid('product_id')->nullable()->index();
                $table->string('description', 255);
                $table->decimal('qty', 12, 4)->default(1.0000);
                $table->decimal('unit_cost', 18, 2)->default(0.00);
                $table->decimal('unit_price', 18, 2)->default(0.00);
                $table->string('charged_to', 30)->default('customer'); // customer, warranty_provision
                $table->string('defective_part_serial', 100)->nullable();
                $table->uuid('supplier_id')->nullable()->index(); // suppliers (no FK)
                $table->string('replacement_part_serial', 100)->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('esm_rma_status_history')) {
            Schema::create('esm_rma_status_history', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('rma_order_id')->constrained('esm_rma_orders')->cascadeOnDelete();
                $table->string('from_status', 40)->nullable();
                $table->string('to_status', 40);
                $table->uuid('actor_user_id')->nullable();
                $table->string('actor_type', 20)->default('user'); // user, customer, system
                $table->text('note')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['rma_order_id', 'created_at'], 'esm_rma_history_order_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('esm_rma_status_history');
        Schema::dropIfExists('esm_rma_lines');
        Schema::dropIfExists('esm_rma_orders');
        Schema::dropIfExists('esm_rma_counters');
    }
};
