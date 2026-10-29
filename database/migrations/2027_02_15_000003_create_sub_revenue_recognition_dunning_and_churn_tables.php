<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. IFRS 15 / ASC 606 Revenue Contracts
        if (! Schema::hasTable('sub_revenue_contracts')) {
            Schema::create('sub_revenue_contracts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->string('contract_number', 50)->index();
                $table->foreignUuid('subscription_id')->constrained('sub_subscriptions')->cascadeOnDelete();
                $table->decimal('total_transaction_price', 15, 2)->default(0.00);
                $table->string('currency', 3)->default('EUR');
                $table->date('inception_date');
                $table->date('termination_date')->nullable();
                $table->string('status', 30)->default('active')->index(); // active, modified, completed, cancelled
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'contract_number']);
            });
        }

        // 2. Performance Obligations (POB: Step 2 & 4 of IFRS 15)
        if (! Schema::hasTable('sub_performance_obligations')) {
            Schema::create('sub_performance_obligations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('revenue_contract_id')->constrained('sub_revenue_contracts')->cascadeOnDelete();
                $table->string('pob_code', 50)->index();
                $table->string('description', 255);
                $table->decimal('standalone_selling_price', 15, 2)->default(0.00);
                $table->decimal('allocated_transaction_price', 15, 2)->default(0.00);
                $table->string('recognition_method', 30)->default('straight_line_over_time'); // straight_line_over_time, point_in_time, milestone, usage_based
                $table->date('start_date');
                $table->date('end_date');
                $table->decimal('recognized_revenue_to_date', 15, 2)->default(0.00);
                $table->decimal('deferred_revenue_balance', 15, 2)->default(0.00);
                $table->uuid('gl_revenue_account_id')->nullable()->index(); // Module 22 General Ledger P&L
                $table->uuid('gl_deferred_account_id')->nullable()->index(); // Module 22 General Ledger Balance Sheet Contract Liability
                $table->string('status', 30)->default('in_progress')->index(); // in_progress, satisfied, terminated
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
            });
        }

        // 3. Revenue Schedules (Periodic Planned Amortization)
        if (! Schema::hasTable('sub_revenue_schedules')) {
            Schema::create('sub_revenue_schedules', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('performance_obligation_id')->constrained('sub_performance_obligations')->cascadeOnDelete();
                $table->date('period_date')->index();
                $table->decimal('scheduled_amount', 15, 2)->default(0.00);
                $table->decimal('recognized_amount', 15, 2)->default(0.00);
                $table->boolean('is_recognized')->default(false)->index();
                $table->timestamp('recognized_at')->nullable();
                $table->uuid('journal_entry_id')->nullable()->index(); // Module 22 Journal Entry
                $table->timestamps();
            });
        }

        // 4. Executed Revenue Recognition Entries (Auditable Accounting Mechanics)
        if (! Schema::hasTable('sub_revenue_recognition_entries')) {
            Schema::create('sub_revenue_recognition_entries', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('performance_obligation_id')->constrained('sub_performance_obligations')->cascadeOnDelete();
                $table->date('recognition_date')->index();
                $table->decimal('amount', 15, 2);
                $table->string('currency', 3)->default('EUR');
                $table->uuid('gl_journal_entry_id')->nullable()->index();
                $table->string('audit_hash', 64)->index();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
            });
        }

        // 5. Dunning Policies (Multi-Tier Dunning Framework)
        if (! Schema::hasTable('sub_dunning_policies')) {
            Schema::create('sub_dunning_policies', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->string('policy_name', 100);
                $table->boolean('is_default')->default(false);
                $table->unsignedSmallInteger('soft_reminder_days')->default(3);
                $table->unsignedSmallInteger('formal_notice_days')->default(10);
                $table->unsignedSmallInteger('suspension_days')->default(25);
                $table->unsignedSmallInteger('termination_days')->default(45);
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
            });
        }

        // 6. Dunning Records (Lifecycle Escalation per Invoice)
        if (! Schema::hasTable('sub_dunning_records')) {
            Schema::create('sub_dunning_records', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('subscription_id')->constrained('sub_subscriptions')->cascadeOnDelete();
                $table->uuid('invoice_id')->index(); // Module 05 Invoicing
                $table->unsignedTinyInteger('dunning_level')->default(1); // 1 = reminder, 2 = warning, 3 = suspension, 4 = legal/terminated
                $table->string('status', 30)->default('active')->index(); // active, resolved, escalated, failed
                $table->string('action_taken', 100);
                $table->jsonb('communications_log')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
            });
        }

        // 7. Churn & Renewal Predictions (AI Risk & Retention Signals)
        if (! Schema::hasTable('sub_churn_predictions')) {
            Schema::create('sub_churn_predictions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('subscription_id')->constrained('sub_subscriptions')->cascadeOnDelete();
                $table->decimal('churn_probability_score', 5, 2); // 0.00 to 100.00%
                $table->string('risk_tier', 20)->default('low')->index(); // low, medium, high, critical
                $table->string('primary_churn_factor', 150);
                $table->text('ai_mitigation_recommendation')->nullable();
                $table->timestamp('analyzed_at');
                $table->timestamps();
            });
        }

        // 8. Partner Commissions & Reseller Margin Tracking
        if (! Schema::hasTable('sub_partner_commissions')) {
            Schema::create('sub_partner_commissions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('partner_id')->index(); // CRM Contact / Partner Master
                $table->foreignUuid('subscription_id')->constrained('sub_subscriptions')->cascadeOnDelete();
                $table->uuid('invoice_id')->nullable()->index();
                $table->decimal('commission_rate', 5, 4)->default(0.1000); // 10%
                $table->decimal('gross_billed_amount', 15, 2)->default(0.00);
                $table->decimal('commission_earned', 15, 2)->default(0.00);
                $table->string('status', 30)->default('accrued')->index(); // accrued, approved, paid
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sub_partner_commissions');
        Schema::dropIfExists('sub_churn_predictions');
        Schema::dropIfExists('sub_dunning_records');
        Schema::dropIfExists('sub_dunning_policies');
        Schema::dropIfExists('sub_revenue_recognition_entries');
        Schema::dropIfExists('sub_revenue_schedules');
        Schema::dropIfExists('sub_performance_obligations');
        Schema::dropIfExists('sub_revenue_contracts');
    }
};
