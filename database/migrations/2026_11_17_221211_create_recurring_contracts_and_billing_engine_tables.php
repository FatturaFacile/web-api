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
        // 1. recurring_contracts
        Schema::create('recurring_contracts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('contract_number', 64);
            $table->string('title');
            $table->string('contract_type', 32); // maintenance, rental, software_license, service_subscription
            $table->string('status', 32)->default('draft'); // draft, active, suspended, expired, cancelled
            $table->date('start_date');
            $table->date('end_date')->nullable(); // nullable for evergreen
            $table->string('billing_frequency', 32)->default('monthly'); // monthly, quarterly, semi_annual, annual, custom_days
            $table->unsignedInteger('custom_interval_days')->nullable();
            $table->string('billing_timing', 32)->default('in_advance'); // in_advance, in_arrears
            $table->date('next_billing_date');
            $table->date('last_billed_date')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->unsignedInteger('renewal_period_months')->nullable();
            $table->unsignedInteger('notice_period_days')->default(30);
            $table->string('currency', 3)->default('CHF'); // CHF, EUR
            $table->foreignUuid('payment_term_id')->nullable()->constrained('payment_terms')->nullOnDelete();
            $table->string('payment_method', 64)->nullable();
            $table->boolean('invoice_auto_issue')->default(false); // false = draft invoice, true = issued
            $table->text('notes')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'contract_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'next_billing_date']);
        });

        // 2. recurring_contract_lines
        Schema::create('recurring_contract_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('recurring_contract_id')->constrained('recurring_contracts')->cascadeOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('description');
            $table->decimal('quantity', 15, 4)->default(1);
            $table->decimal('unit_price', 15, 4)->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->foreignUuid('vat_rate_id')->nullable()->constrained('vat_rates')->nullOnDelete();
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->string('proration_mode', 32)->default('none'); // none, daily_exact, thirty_day_month
            $table->timestamps();

            $table->index(['tenant_id', 'recurring_contract_id']);
        });

        // 3. contract_indexation_clauses
        Schema::create('contract_indexation_clauses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('recurring_contract_id')->constrained('recurring_contracts')->cascadeOnDelete();
            $table->string('index_type', 32); // none, istat_foi, swiss_ipc
            $table->decimal('base_index_value', 10, 4);
            $table->string('base_index_period', 7); // YYYY-MM
            $table->unsignedInteger('adjustment_frequency_months')->default(12);
            $table->decimal('pass_through_percent', 5, 2)->default(100.00); // e.g. 100.00 or 75.00
            $table->decimal('floor_percent', 5, 2)->nullable();
            $table->decimal('cap_percent', 5, 2)->nullable();
            $table->date('next_adjustment_date');
            $table->timestamp('last_adjusted_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'recurring_contract_id']);
            $table->index(['tenant_id', 'next_adjustment_date']);
        });

        // 4. price_index_rates
        Schema::create('price_index_rates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('index_type', 32); // istat_foi, swiss_ipc
            $table->string('period', 7); // YYYY-MM
            $table->decimal('index_value', 10, 4);
            $table->date('published_at')->nullable();
            $table->string('source_reference')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'index_type', 'period']);
            $table->index(['tenant_id', 'period']);
            $table->index(['index_type', 'period']);
        });

        // 5. contract_indexation_logs
        Schema::create('contract_indexation_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('recurring_contract_id')->constrained('recurring_contracts')->cascadeOnDelete();
            $table->foreignUuid('clause_id')->constrained('contract_indexation_clauses')->cascadeOnDelete();
            $table->string('period_from', 7); // YYYY-MM
            $table->string('period_to', 7);   // YYYY-MM
            $table->decimal('old_index_value', 10, 4);
            $table->decimal('new_index_value', 10, 4);
            $table->decimal('raw_delta_percent', 8, 4);
            $table->decimal('applied_delta_percent', 8, 4);
            $table->decimal('old_recurring_total', 15, 4);
            $table->decimal('new_recurring_total', 15, 4);
            $table->timestamp('applied_at');
            $table->foreignUuid('applied_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'recurring_contract_id']);
        });

        // 6. recurring_billing_runs
        Schema::create('recurring_billing_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('run_type', 32)->default('scheduled'); // scheduled, manual
            $table->date('target_date');
            $table->string('status', 32)->default('running'); // running, completed, partial_failure, failed
            $table->unsignedInteger('contracts_processed')->default(0);
            $table->unsignedInteger('invoices_generated')->default(0);
            $table->decimal('total_amount', 15, 4)->default(0);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'target_date']);
        });

        // 7. recurring_billing_run_items
        Schema::create('recurring_billing_run_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('run_id')->constrained('recurring_billing_runs')->cascadeOnDelete();
            $table->foreignUuid('recurring_contract_id')->constrained('recurring_contracts')->cascadeOnDelete();
            $table->date('billing_period_start');
            $table->date('billing_period_end');
            $table->string('idempotency_key', 128);
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('status', 32)->default('success'); // success, skipped, failed
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'run_id']);
            $table->index(['tenant_id', 'recurring_contract_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recurring_billing_run_items');
        Schema::dropIfExists('recurring_billing_runs');
        Schema::dropIfExists('contract_indexation_logs');
        Schema::dropIfExists('price_index_rates');
        Schema::dropIfExists('contract_indexation_clauses');
        Schema::dropIfExists('recurring_contract_lines');
        Schema::dropIfExists('recurring_contracts');
    }
};
