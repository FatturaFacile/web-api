<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Lead Sources
        Schema::create('crm_lead_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'is_active']);
        });

        // 2. Campaign Types
        Schema::create('crm_campaign_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'is_active']);
        });

        // 3. Campaigns
        Schema::create('crm_campaigns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('campaign_type_id')->nullable()->constrained('crm_campaign_types')->nullOnDelete();
            $table->string('code', 50);
            $table->string('name', 200);
            $table->string('status', 30)->default('planned')->index(); // planned, active, completed, cancelled
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('budgeted_cost', 15, 2)->default(0.00);
            $table->decimal('actual_cost', 15, 2)->default(0.00);
            $table->decimal('expected_revenue', 15, 2)->default(0.00);
            $table->decimal('actual_revenue', 15, 2)->default(0.00);
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 100)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status', 'start_date']);
        });

        // 4. Accounts / Organizations / Companies
        Schema::create('crm_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->uuid('parent_account_id')->nullable();
            $table->string('account_number', 50);
            $table->string('name', 200);
            $table->string('industry', 100)->nullable();
            $table->string('tier', 30)->default('standard'); // strategic, enterprise, mid_market, smb, standard
            $table->string('status', 30)->default('active')->index(); // prospect, active, inactive, churned
            $table->decimal('annual_revenue', 15, 2)->nullable();
            $table->unsignedInteger('employee_count')->nullable();
            $table->string('website', 255)->nullable();
            $table->uuid('owner_user_id')->nullable()->index();
            $table->decimal('customer_health_index', 5, 2)->default(100.00)->index();
            $table->string('churn_risk', 20)->default('low')->index(); // low, medium, high, critical
            $table->string('lifecycle_stage', 30)->default('prospect'); // lead, prospect, customer, evangelist, churned
            $table->timestamp('last_activity_at')->nullable();
            $table->jsonb('tags')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'account_number']);
            $table->index(['tenant_id', 'tier', 'status']);
            $table->index(['tenant_id', 'customer_health_index']);
        });

        Schema::table('crm_accounts', function (Blueprint $table): void {
            $table->foreign('parent_account_id')->references('id')->on('crm_accounts')->nullOnDelete();
        });

        // 5. Leads
        Schema::create('crm_leads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('lead_source_id')->nullable()->constrained('crm_lead_sources')->nullOnDelete();
            $table->foreignUuid('campaign_id')->nullable()->constrained('crm_campaigns')->nullOnDelete();
            $table->uuid('assigned_user_id')->nullable()->index();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('company_name', 200)->nullable();
            $table->string('title', 100)->nullable();
            $table->string('email', 150)->nullable()->index();
            $table->string('phone', 50)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('country', 2)->default('IT');
            $table->string('status', 30)->default('new')->index(); // new, contacting, engaged, qualified, disqualified, converted, archived
            $table->string('disqualification_reason')->nullable();
            $table->integer('score')->default(0)->index();
            $table->string('rating', 20)->default('cold'); // hot, warm, cold
            $table->decimal('estimated_budget', 15, 2)->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->foreignUuid('converted_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignUuid('converted_account_id')->nullable()->constrained('crm_accounts')->nullOnDelete();
            $table->uuid('converted_opportunity_id')->nullable()->index();
            $table->string('duplicate_hash', 64)->nullable()->index();
            $table->jsonb('tags')->nullable();
            $table->jsonb('custom_fields')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status', 'score']);
            $table->index(['tenant_id', 'company_name', 'email']);
        });

        // 6. Campaign Members
        Schema::create('crm_campaign_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('campaign_id')->constrained('crm_campaigns')->cascadeOnDelete();
            $table->foreignUuid('lead_id')->nullable()->constrained('crm_leads')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->cascadeOnDelete();
            $table->string('status', 30)->default('sent'); // sent, responded, attended, converted, bounced
            $table->timestamp('first_associated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'campaign_id', 'status']);
        });

        // 7. Contact Roles in Accounts
        Schema::create('crm_contact_roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('account_id')->constrained('crm_accounts')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('role', 50)->default('evaluator'); // decision_maker, influencer, champion, evaluator, blocker, economic_buyer, technical_buyer
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'account_id', 'role']);
            $table->index(['tenant_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_contact_roles');
        Schema::dropIfExists('crm_campaign_members');
        Schema::dropIfExists('crm_leads');
        Schema::dropIfExists('crm_accounts');
        Schema::dropIfExists('crm_campaigns');
        Schema::dropIfExists('crm_campaign_types');
        Schema::dropIfExists('crm_lead_sources');
    }
};
