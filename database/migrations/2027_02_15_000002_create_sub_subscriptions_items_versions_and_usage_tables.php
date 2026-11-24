<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Master Subscriptions (Contract Agreements & Lifecycle)
        if (! Schema::hasTable('sub_subscriptions')) {
            Schema::create('sub_subscriptions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->string('subscription_number', 50)->index();
                $table->uuid('customer_id')->index(); // Module 02 CRM Contact/Customer Master
                $table->foreignUuid('plan_id')->constrained('sub_plans')->restrictOnDelete();
                $table->string('status', 30)->default('draft')->index(); // draft, pending, active, paused, past_due, cancelling, cancelled, expired
                $table->date('start_date');
                $table->date('current_period_start');
                $table->date('current_period_end');
                $table->string('renewal_type', 20)->default('evergreen'); // evergreen, fixed_term, manual
                $table->unsignedInteger('contract_term_months')->default(12);
                $table->boolean('auto_renew')->default(true);
                $table->unsignedSmallInteger('cancellation_notice_days')->default(30);
                $table->timestamp('cancelled_at')->nullable();
                $table->string('cancellation_reason', 150)->nullable();
                $table->string('currency', 3)->default('EUR');
                $table->unsignedSmallInteger('billing_day_of_month')->default(1);
                $table->unsignedSmallInteger('payment_terms_days')->default(30);
                $table->string('payment_method', 30)->default('sepa_direct_debit'); // sepa_direct_debit, bank_transfer, credit_card, cash
                $table->uuid('bank_account_id')->nullable()->index(); // Module 24 Treasury
                $table->decimal('mrr', 15, 2)->default(0.00);
                $table->decimal('arr', 15, 2)->default(0.00);
                $table->uuid('workflow_instance_id')->nullable()->index(); // Module 21 Workflow
                $table->boolean('is_locked')->default(false);
                $table->jsonb('terms')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'subscription_number']);
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('CREATE INDEX sub_subscriptions_metadata_gin ON sub_subscriptions USING GIN (metadata)');
                DB::statement('CREATE INDEX sub_subscriptions_active_idx ON sub_subscriptions (tenant_id, status) WHERE status = \'active\'');
            }
        }

        // 2. Subscription Line Items (Plans, Add-ons, Retainers, Usage Meters)
        if (! Schema::hasTable('sub_subscription_items')) {
            Schema::create('sub_subscription_items', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('subscription_id')->constrained('sub_subscriptions')->cascadeOnDelete();
                $table->foreignUuid('plan_id')->nullable()->constrained('sub_plans')->nullOnDelete();
                $table->foreignUuid('product_id')->nullable()->constrained('sub_products')->nullOnDelete();
                $table->string('item_type', 30)->default('plan'); // plan, addon, usage_meter, support_retainer, hardware_lease
                $table->string('description', 255);
                $table->decimal('quantity', 14, 4)->default(1.0000);
                $table->decimal('unit_price', 15, 4)->default(0.0000);
                $table->decimal('discount_percentage', 5, 2)->default(0.00);
                $table->decimal('subtotal_recurring', 15, 2)->default(0.00);
                $table->foreignUuid('metric_id')->nullable()->constrained('sub_usage_metrics')->nullOnDelete();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['subscription_id', 'item_type']);
            });
        }

        // 3. Subscription Versions (Amendments, Upgrades, Downgrades & Proration Trail)
        if (! Schema::hasTable('sub_subscription_versions')) {
            Schema::create('sub_subscription_versions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('subscription_id')->constrained('sub_subscriptions')->cascadeOnDelete();
                $table->unsignedInteger('version_number')->default(1);
                $table->date('effective_date');
                $table->string('change_type', 30)->default('initial'); // initial, upgrade, downgrade, amendment, cancellation, renewal
                $table->decimal('mrr_before', 15, 2)->default(0.00);
                $table->decimal('mrr_after', 15, 2)->default(0.00);
                $table->decimal('mrr_delta', 15, 2)->default(0.00);
                $table->decimal('proration_amount', 15, 2)->default(0.00);
                $table->jsonb('snapshot_payload');
                $table->string('change_reason', 255)->nullable();
                $table->uuid('actor_user_id')->nullable()->index();
                $table->timestamps();

                $table->unique(['subscription_id', 'version_number']);
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('CREATE INDEX sub_sub_versions_snapshot_gin ON sub_subscription_versions USING GIN (snapshot_payload)');
            }
        }

        // 4. Metered Usage Ingestion Events (High Throughput & Idempotency)
        if (! Schema::hasTable('sub_usage_events')) {
            Schema::create('sub_usage_events', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('subscription_id')->constrained('sub_subscriptions')->cascadeOnDelete();
                $table->foreignUuid('subscription_item_id')->constrained('sub_subscription_items')->cascadeOnDelete();
                $table->string('metric_code', 50)->index();
                $table->string('idempotency_key', 120)->index();
                $table->decimal('quantity', 15, 4);
                $table->timestamp('event_timestamp');
                $table->boolean('is_processed')->default(false)->index();
                $table->timestamp('processed_at')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'idempotency_key']);
            });
        }

        // 5. Usage Aggregations (Cycle Summaries & Overage Rating)
        if (! Schema::hasTable('sub_usage_aggregations')) {
            Schema::create('sub_usage_aggregations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('subscription_item_id')->constrained('sub_subscription_items')->cascadeOnDelete();
                $table->date('period_start');
                $table->date('period_end');
                $table->decimal('total_consumed', 15, 4)->default(0.0000);
                $table->decimal('included_allowance', 15, 4)->default(0.0000);
                $table->decimal('billable_overage', 15, 4)->default(0.0000);
                $table->decimal('unit_price', 15, 4)->default(0.0000);
                $table->decimal('billable_amount', 15, 2)->default(0.00);
                $table->boolean('is_billed')->default(false)->index();
                $table->timestamp('billed_at')->nullable();
                $table->timestamps();

                $table->unique(['subscription_item_id', 'period_start', 'period_end'], 'uq_sub_usage_agg_item_period');
            });
        }

        // 6. Billing Runs (Automated Cycle Runs & Batch Audit)
        if (! Schema::hasTable('sub_billing_runs')) {
            Schema::create('sub_billing_runs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->string('run_number', 50)->index();
                $table->date('period_start');
                $table->date('period_end');
                $table->date('execution_date');
                $table->unsignedInteger('total_subscriptions_evaluated')->default(0);
                $table->unsignedInteger('successful_invoices_count')->default(0);
                $table->unsignedInteger('failed_invoices_count')->default(0);
                $table->decimal('total_invoiced_amount', 15, 2)->default(0.00);
                $table->string('status', 30)->default('pending')->index(); // pending, processing, completed, failed
                $table->jsonb('errors_log')->nullable();
                $table->uuid('executed_by')->nullable()->index();
                $table->timestamps();

                $table->unique(['tenant_id', 'run_number']);
            });
        }

        // 7. Invoice Schedules (Pre-Calculated Due Dates & Invoicing Linkage)
        if (! Schema::hasTable('sub_invoice_schedules')) {
            Schema::create('sub_invoice_schedules', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('subscription_id')->constrained('sub_subscriptions')->cascadeOnDelete();
                $table->date('scheduled_billing_date')->index();
                $table->date('service_period_start');
                $table->date('service_period_end');
                $table->decimal('estimated_amount', 15, 2)->default(0.00);
                $table->string('status', 30)->default('scheduled')->index(); // scheduled, invoiced, skipped, cancelled
                $table->uuid('invoice_id')->nullable()->index(); // Module 05 Invoicing
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sub_invoice_schedules');
        Schema::dropIfExists('sub_billing_runs');
        Schema::dropIfExists('sub_usage_aggregations');
        Schema::dropIfExists('sub_usage_events');
        Schema::dropIfExists('sub_subscription_versions');
        Schema::dropIfExists('sub_subscription_items');
        Schema::dropIfExists('sub_subscriptions');
    }
};
