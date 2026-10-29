<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Opportunity Stages
        Schema::create('crm_opportunity_stages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 50); // discovery, qualification, proposal, negotiation, closed_won, closed_lost
            $table->string('name', 100);
            $table->unsignedInteger('sequence')->default(10);
            $table->decimal('default_probability', 5, 2)->default(10.00);
            $table->boolean('is_won')->default(false);
            $table->boolean('is_lost')->default(false);
            $table->unsignedInteger('stale_days')->default(30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'sequence']);
        });

        // 2. Opportunities
        Schema::create('crm_opportunities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('account_id')->constrained('crm_accounts')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignUuid('lead_id')->nullable()->constrained('crm_leads')->nullOnDelete();
            $table->foreignUuid('campaign_id')->nullable()->constrained('crm_campaigns')->nullOnDelete();
            $table->foreignUuid('stage_id')->constrained('crm_opportunity_stages')->restrictOnDelete();
            $table->uuid('owner_user_id')->nullable()->index();
            $table->string('opportunity_number', 50);
            $table->string('name', 200);
            $table->decimal('amount', 15, 2)->default(0.00);
            $table->decimal('expected_revenue', 15, 2)->default(0.00);
            $table->string('currency', 3)->default('EUR');
            $table->decimal('probability', 5, 2)->default(10.00);
            $table->string('forecast_category', 30)->default('pipeline')->index(); // pipeline, best_case, commit, closed, omitted
            $table->date('expected_close_date');
            $table->date('actual_close_date')->nullable();
            $table->string('win_loss_reason')->nullable();
            $table->string('competitor', 150)->nullable();
            $table->string('status', 30)->default('open')->index(); // open, won, lost, cancelled
            $table->decimal('discount_percentage', 5, 2)->default(0.00);
            $table->uuid('workflow_instance_id')->nullable()->index();
            $table->string('approval_status', 30)->default('not_required')->index(); // not_required, pending_approval, approved, rejected
            $table->foreignUuid('sales_quote_id')->nullable()->constrained('quotes')->nullOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->jsonb('tags')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'opportunity_number']);
            $table->index(['tenant_id', 'stage_id', 'status']);
            $table->index(['tenant_id', 'expected_close_date']);
            $table->index(['tenant_id', 'amount']);
        });

        // 3. Opportunity Line Items
        Schema::create('crm_opportunity_line_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('opportunity_id')->constrained('crm_opportunities')->cascadeOnDelete();
            $table->uuid('product_id')->nullable();
            $table->string('description', 255);
            $table->decimal('quantity', 15, 4)->default(1.0000);
            $table->decimal('unit_price', 15, 2)->default(0.00);
            $table->decimal('total_price', 15, 2)->default(0.00);
            $table->decimal('discount_percent', 5, 2)->default(0.00);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'opportunity_id']);
        });

        // 4. CRM Activities (Tasks, Calls, Meetings, Notes, Follow-ups, Emails)
        Schema::create('crm_activities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('subject', 200);
            $table->string('type', 30)->index(); // task, call, meeting, note, email, follow_up
            $table->string('status', 30)->default('pending')->index(); // pending, in_progress, completed, cancelled
            $table->string('priority', 20)->default('medium'); // low, medium, high, urgent
            $table->timestamp('due_date')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->uuid('assigned_user_id')->nullable()->index();
            $table->uuid('created_by_user_id')->nullable()->index();
            $table->string('related_type', 50)->nullable()->index(); // lead, account, contact, opportunity, campaign
            $table->uuid('related_id')->nullable()->index();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('location', 255)->nullable();
            $table->string('outcome', 100)->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'related_type', 'related_id']);
            $table->index(['tenant_id', 'status', 'due_date']);
        });

        // 5. Customer Health Profiles
        Schema::create('crm_customer_health_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('account_id')->constrained('crm_accounts')->cascadeOnDelete();
            $table->decimal('activity_score', 5, 2)->default(50.00);
            $table->decimal('engagement_score', 5, 2)->default(50.00);
            $table->decimal('revenue_score', 5, 2)->default(50.00);
            $table->decimal('risk_score', 5, 2)->default(10.00);
            $table->string('churn_risk_indicator', 20)->default('low')->index(); // low, medium, high, critical
            $table->decimal('health_index', 5, 2)->default(85.00)->index();
            $table->timestamp('last_interaction_at')->nullable();
            $table->timestamp('last_invoiced_at')->nullable();
            $table->decimal('open_receivable_amount', 15, 2)->default(0.00);
            $table->decimal('overdue_receivable_amount', 15, 2)->default(0.00);
            $table->jsonb('evaluation_metrics')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'account_id']);
            $table->index(['tenant_id', 'health_index', 'churn_risk_indicator']);
        });

        // 6. Sales & Revenue Forecasts
        Schema::create('crm_forecasts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('user_id')->nullable()->index();
            $table->string('period', 20)->index(); // e.g. '2027-Q1', '2027-01'
            $table->string('currency', 3)->default('EUR');
            $table->decimal('pipeline_amount', 15, 2)->default(0.00);
            $table->decimal('best_case_amount', 15, 2)->default(0.00);
            $table->decimal('commit_amount', 15, 2)->default(0.00);
            $table->decimal('closed_amount', 15, 2)->default(0.00);
            $table->decimal('weighted_amount', 15, 2)->default(0.00);
            $table->decimal('quota_amount', 15, 2)->default(0.00);
            $table->decimal('attainment_percent', 5, 2)->default(0.00);
            $table->string('status', 30)->default('draft')->index(); // draft, submitted, approved, locked
            $table->uuid('workflow_instance_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'user_id', 'period']);
            $table->index(['tenant_id', 'period', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_forecasts');
        Schema::dropIfExists('crm_customer_health_profiles');
        Schema::dropIfExists('crm_activities');
        Schema::dropIfExists('crm_opportunity_line_items');
        Schema::dropIfExists('crm_opportunities');
        Schema::dropIfExists('crm_opportunity_stages');
    }
};
