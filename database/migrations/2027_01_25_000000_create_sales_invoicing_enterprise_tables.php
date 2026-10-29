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
        if (! Schema::hasTable('customer_groups')) {
            Schema::create('customer_groups', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('code', 50)->index();
                $table->string('name', 100);
                $table->text('description')->nullable();
                $table->decimal('discount_percentage', 5, 2)->default(0);
                $table->foreignUuid('price_list_id')->nullable()->constrained('price_lists')->nullOnDelete();
                $table->foreignUuid('payment_term_id')->nullable()->constrained('payment_terms')->nullOnDelete();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code']);
            });
        }

        if (! Schema::hasTable('customer_agreements')) {
            Schema::create('customer_agreements', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('customer_id')->constrained('contacts')->cascadeOnDelete();
                $table->string('agreement_number', 50)->index();
                $table->string('title', 150);
                $table->date('start_date');
                $table->date('end_date')->nullable();
                $table->decimal('total_committed_amount', 15, 2)->default(0);
                $table->decimal('consumed_amount', 15, 2)->default(0);
                $table->string('currency', 3)->default('EUR');
                $table->string('status', 30)->default('draft')->index(); // draft, active, suspended, expired, terminated
                $table->foreignUuid('price_list_id')->nullable()->constrained('price_lists')->nullOnDelete();
                $table->foreignUuid('payment_term_id')->nullable()->constrained('payment_terms')->nullOnDelete();
                $table->text('terms_and_conditions')->nullable();
                $table->timestamp('signed_at')->nullable();
                $table->uuid('created_by')->nullable();
                $table->uuid('approved_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'agreement_number']);
            });
        }

        if (! Schema::hasTable('customer_credit_profiles')) {
            Schema::create('customer_credit_profiles', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('customer_id')->constrained('contacts')->cascadeOnDelete();
                $table->decimal('credit_limit', 15, 2)->default(0);
                $table->decimal('used_credit', 15, 2)->default(0);
                $table->decimal('pending_orders_amount', 15, 2)->default(0);
                $table->decimal('total_exposure', 15, 2)->default(0);
                $table->decimal('overdue_amount', 15, 2)->default(0);
                $table->unsignedInteger('overdue_invoices_count')->default(0);
                $table->unsignedInteger('max_overdue_days')->default(0);
                $table->string('credit_status', 30)->default('good')->index(); // good, warning, hold, blocked
                $table->string('risk_rating', 20)->default('low'); // low, medium, high, critical
                $table->boolean('is_credit_hold')->default(false)->index();
                $table->text('hold_reason')->nullable();
                $table->timestamp('last_evaluated_at')->nullable();
                $table->uuid('evaluated_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'customer_id']);
            });
        }

        if (! Schema::hasTable('discount_policies')) {
            Schema::create('discount_policies', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('code', 50)->index();
                $table->string('name', 100);
                $table->string('discount_type', 30)->default('percentage'); // percentage, fixed_amount, volume_tier
                $table->decimal('value', 15, 4)->default(0);
                $table->decimal('min_order_amount', 15, 2)->default(0);
                $table->decimal('min_quantity', 12, 4)->default(0);
                $table->date('valid_from')->nullable();
                $table->date('valid_to')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code']);
            });
        }

        if (! Schema::hasTable('discount_policy_rules')) {
            Schema::create('discount_policy_rules', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('discount_policy_id')->constrained('discount_policies')->cascadeOnDelete();
                $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
                $table->foreignUuid('customer_group_id')->nullable()->constrained('customer_groups')->nullOnDelete();
                $table->decimal('min_threshold', 15, 2)->default(0);
                $table->decimal('discount_percent', 5, 2)->default(0);
                $table->decimal('discount_fixed_amount', 15, 2)->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('sales_opportunities')) {
            Schema::create('sales_opportunities', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('customer_id')->constrained('contacts')->cascadeOnDelete();
                $table->string('opportunity_number', 50)->index();
                $table->string('title', 150);
                $table->string('stage', 30)->default('lead')->index(); // lead, qualification, proposal, negotiation, won, lost
                $table->decimal('estimated_amount', 15, 2)->default(0);
                $table->string('currency', 3)->default('EUR');
                $table->decimal('probability_percent', 5, 2)->default(0);
                $table->date('expected_close_date')->nullable();
                $table->uuid('assigned_to_user_id')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'opportunity_number']);
            });
        }

        if (! Schema::hasTable('sales_representatives')) {
            Schema::create('sales_representatives', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('user_id')->nullable();
                $table->string('rep_code', 50)->index();
                $table->string('name', 100);
                $table->string('email', 150)->nullable();
                $table->string('phone', 50)->nullable();
                $table->string('territory', 100)->nullable();
                $table->decimal('default_commission_rate', 5, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'rep_code']);
            });
        }

        if (! Schema::hasTable('sales_commissions')) {
            Schema::create('sales_commissions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('sales_representative_id')->constrained('sales_representatives')->cascadeOnDelete();
                $table->foreignUuid('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
                $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
                $table->decimal('base_amount', 15, 2)->default(0);
                $table->decimal('commission_rate', 5, 2)->default(0);
                $table->decimal('commission_amount', 15, 2)->default(0);
                $table->string('status', 30)->default('accrued')->index(); // accrued, approved, paid, cancelled
                $table->timestamp('paid_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('sales_order_holds')) {
            Schema::create('sales_order_holds', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
                $table->string('hold_type', 50)->index(); // credit_limit, overdue_invoices, fraud_review, manager_discretion, pricing_threshold
                $table->string('status', 30)->default('active')->index(); // active, released, rejected
                $table->text('hold_reason');
                $table->uuid('placed_by')->nullable();
                $table->timestamp('placed_at');
                $table->uuid('released_by')->nullable();
                $table->timestamp('released_at')->nullable();
                $table->text('release_notes')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('customer_ledger_entries')) {
            Schema::create('customer_ledger_entries', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('customer_id')->constrained('contacts')->cascadeOnDelete();
                $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
                $table->string('entry_number', 50)->index();
                $table->date('entry_date');
                $table->date('due_date')->nullable();
                $table->string('entry_type', 30)->index(); // invoice, credit_note, debit_note, payment, refund, fx_adjustment
                $table->string('document_reference', 100)->nullable();
                $table->decimal('debit_amount', 15, 2)->default(0);
                $table->decimal('credit_amount', 15, 2)->default(0);
                $table->decimal('balance_after', 15, 2)->default(0);
                $table->string('currency', 3)->default('EUR');
                $table->decimal('exchange_rate', 16, 6)->default(1.0);
                $table->string('status', 30)->default('open')->index(); // open, partially_cleared, cleared, disputed
                $table->decimal('cleared_amount', 15, 2)->default(0);
                $table->timestamp('cleared_at')->nullable();
                $table->uuid('gl_journal_entry_id')->nullable();
                $table->text('notes')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'customer_id', 'entry_date']);
                $table->index(['tenant_id', 'status', 'due_date']);
            });
        }

        if (! Schema::hasTable('billing_schedules')) {
            Schema::create('billing_schedules', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('customer_id')->constrained('contacts')->cascadeOnDelete();
                $table->foreignUuid('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
                $table->foreignUuid('customer_agreement_id')->nullable()->constrained('customer_agreements')->nullOnDelete();
                $table->string('schedule_number', 50)->index();
                $table->string('schedule_type', 30)->default('milestone'); // milestone, recurring, percentage_completion
                $table->string('frequency', 30)->nullable(); // monthly, quarterly, annual
                $table->decimal('total_amount', 15, 2)->default(0);
                $table->decimal('billed_amount', 15, 2)->default(0);
                $table->decimal('remaining_amount', 15, 2)->default(0);
                $table->string('currency', 3)->default('EUR');
                $table->string('status', 30)->default('active')->index(); // active, completed, suspended, cancelled
                $table->date('start_date');
                $table->date('end_date')->nullable();
                $table->date('next_billing_date')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'schedule_number']);
            });
        }

        if (! Schema::hasTable('billing_schedule_items')) {
            Schema::create('billing_schedule_items', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('billing_schedule_id')->constrained('billing_schedules')->cascadeOnDelete();
                $table->string('milestone_name', 150);
                $table->uuid('milestone_id')->nullable();
                $table->decimal('percentage', 5, 2)->nullable();
                $table->decimal('amount', 15, 2)->default(0);
                $table->date('target_date');
                $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
                $table->string('status', 30)->default('pending')->index(); // pending, ready, invoiced, cancelled
                $table->timestamp('invoiced_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('invoice_credit_applications')) {
            Schema::create('invoice_credit_applications', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('credit_note_id')->constrained('invoices')->cascadeOnDelete();
                $table->foreignUuid('original_invoice_id')->constrained('invoices')->cascadeOnDelete();
                $table->decimal('applied_amount', 15, 2);
                $table->date('application_date');
                $table->text('notes')->nullable();
                $table->uuid('applied_by')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_credit_applications');
        Schema::dropIfExists('billing_schedule_items');
        Schema::dropIfExists('billing_schedules');
        Schema::dropIfExists('customer_ledger_entries');
        Schema::dropIfExists('sales_order_holds');
        Schema::dropIfExists('sales_commissions');
        Schema::dropIfExists('sales_representatives');
        Schema::dropIfExists('sales_opportunities');
        Schema::dropIfExists('discount_policy_rules');
        Schema::dropIfExists('discount_policies');
        Schema::dropIfExists('customer_credit_profiles');
        Schema::dropIfExists('customer_agreements');
        Schema::dropIfExists('customer_groups');
    }
};
