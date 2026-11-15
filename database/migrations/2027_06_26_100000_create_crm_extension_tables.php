<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CRM extension: sales territories and quotas, partner relationship management (tiers, partners, deal registration,
     * MDF), CPQ (quote configurations, pricing rules, discount approval thresholds) and marketing automation sequences.
     * New tables only; business rules are enforced by the application, never by the engine.
     */
    public function up(): void
    {
        $this->territories();
        $this->partners();
        $this->cpq();
        $this->automation();
    }

    private function tenantKey(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
    }

    private function territories(): void
    {
        if (! Schema::hasTable('crm_territories')) {
            Schema::create('crm_territories', function (Blueprint $table): void {
                $this->tenantKey($table);
                $table->string('code', 40);
                $table->string('name', 150);
                $table->uuid('owner_user_id')->nullable()->index();
                $table->json('criteria')->nullable();
                $table->unsignedInteger('priority')->default(100);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code']);
            });
        }

        if (! Schema::hasTable('crm_account_territories')) {
            Schema::create('crm_account_territories', function (Blueprint $table): void {
                $this->tenantKey($table);
                $table->foreignUuid('account_id')->constrained('crm_accounts')->cascadeOnDelete();
                $table->foreignUuid('territory_id')->constrained('crm_territories')->cascadeOnDelete();
                $table->string('source', 20)->default('rule');
                $table->timestamp('assigned_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'account_id']);
                $table->index(['tenant_id', 'territory_id']);
            });
        }

        if (! Schema::hasTable('crm_sales_quotas')) {
            Schema::create('crm_sales_quotas', function (Blueprint $table): void {
                $this->tenantKey($table);
                $table->uuid('user_id')->index();
                $table->foreignUuid('territory_id')->nullable()->constrained('crm_territories')->nullOnDelete();
                $table->date('period_start');
                $table->date('period_end');
                $table->decimal('target_amount', 15, 2);
                $table->string('currency', 3)->default('EUR');
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'user_id', 'period_start']);
            });
        }
    }

    private function partners(): void
    {
        if (! Schema::hasTable('crm_partner_tiers')) {
            Schema::create('crm_partner_tiers', function (Blueprint $table): void {
                $this->tenantKey($table);
                $table->string('code', 40);
                $table->string('name', 100);
                $table->unsignedInteger('rank')->default(1);
                $table->decimal('min_annual_revenue', 15, 2)->default(0);
                $table->decimal('discount_percent', 5, 2)->default(0);
                $table->decimal('mdf_percent', 5, 2)->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code']);
            });
        }

        if (! Schema::hasTable('crm_partners')) {
            Schema::create('crm_partners', function (Blueprint $table): void {
                $this->tenantKey($table);
                $table->foreignUuid('account_id')->nullable()->constrained('crm_accounts')->nullOnDelete();
                $table->foreignUuid('tier_id')->nullable()->constrained('crm_partner_tiers')->nullOnDelete();
                $table->string('name', 200);
                $table->string('partner_type', 20)->default('reseller');
                $table->string('status', 20)->default('prospect');
                $table->string('contact_email', 190)->nullable();
                $table->date('joined_on')->nullable();
                $table->decimal('closed_revenue', 15, 2)->default(0);
                $table->uuid('manager_user_id')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('crm_deal_registrations')) {
            Schema::create('crm_deal_registrations', function (Blueprint $table): void {
                $this->tenantKey($table);
                $table->foreignUuid('partner_id')->constrained('crm_partners')->cascadeOnDelete();
                $table->foreignUuid('opportunity_id')->nullable()->constrained('crm_opportunities')->nullOnDelete();
                $table->string('customer_name', 200);
                $table->string('customer_key', 200)->index();
                $table->decimal('expected_amount', 15, 2)->default(0);
                $table->string('currency', 3)->default('EUR');
                $table->string('status', 20)->default('pending');
                $table->uuid('conflicts_with_id')->nullable();
                $table->date('registered_on');
                $table->date('expires_on')->nullable();
                $table->uuid('decided_by_user_id')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->text('decision_reason')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'status']);
                $table->index(['tenant_id', 'customer_key', 'status']);
            });
        }

        if (! Schema::hasTable('crm_mdf_funds')) {
            Schema::create('crm_mdf_funds', function (Blueprint $table): void {
                $this->tenantKey($table);
                $table->foreignUuid('partner_id')->constrained('crm_partners')->cascadeOnDelete();
                $table->unsignedSmallInteger('fiscal_year');
                $table->decimal('allocated_amount', 15, 2);
                $table->string('currency', 3)->default('EUR');
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'partner_id', 'fiscal_year']);
            });
        }

        if (! Schema::hasTable('crm_mdf_claims')) {
            Schema::create('crm_mdf_claims', function (Blueprint $table): void {
                $this->tenantKey($table);
                $table->foreignUuid('fund_id')->constrained('crm_mdf_funds')->cascadeOnDelete();
                $table->string('activity', 200);
                $table->decimal('amount', 15, 2);
                $table->string('status', 20)->default('submitted');
                $table->date('activity_date')->nullable();
                $table->uuid('decided_by_user_id')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->text('decision_reason')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'fund_id', 'status']);
            });
        }
    }

    private function cpq(): void
    {
        if (! Schema::hasTable('crm_pricing_rules')) {
            Schema::create('crm_pricing_rules', function (Blueprint $table): void {
                $this->tenantKey($table);
                $table->string('name', 150);
                $table->string('rule_type', 30)->default('volume_discount');
                $table->decimal('min_quantity', 12, 3)->default(0);
                $table->string('partner_tier_code', 40)->nullable();
                $table->decimal('discount_percent', 5, 2)->default(0);
                $table->unsignedInteger('priority')->default(100);
                $table->date('valid_from')->nullable();
                $table->date('valid_to')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('crm_discount_thresholds')) {
            Schema::create('crm_discount_thresholds', function (Blueprint $table): void {
                $this->tenantKey($table);
                $table->decimal('from_discount_percent', 5, 2);
                $table->string('approver_role', 30);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'from_discount_percent']);
            });
        }

        if (! Schema::hasTable('crm_quote_configurations')) {
            Schema::create('crm_quote_configurations', function (Blueprint $table): void {
                $this->tenantKey($table);
                $table->foreignUuid('opportunity_id')->nullable()->constrained('crm_opportunities')->nullOnDelete();
                $table->uuid('product_configuration_id')->nullable()->index();
                $table->foreignUuid('partner_id')->nullable()->constrained('crm_partners')->nullOnDelete();
                $table->string('name', 200);
                $table->decimal('quantity', 12, 3)->default(1);
                $table->decimal('unit_price', 15, 4)->default(0);
                $table->string('currency', 3)->default('EUR');
                $table->decimal('rule_discount_percent', 5, 2)->default(0);
                $table->decimal('extra_discount_percent', 5, 2)->default(0);
                $table->decimal('discount_percent', 5, 2)->default(0);
                $table->decimal('net_total', 15, 2)->default(0);
                $table->string('status', 20)->default('draft');
                $table->string('required_approver_role', 30)->nullable();
                $table->uuid('decided_by_user_id')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->text('decision_reason')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'status']);
            });
        }
    }

    private function automation(): void
    {
        if (! Schema::hasTable('crm_automation_sequences')) {
            Schema::create('crm_automation_sequences', function (Blueprint $table): void {
                $this->tenantKey($table);
                $table->string('name', 150);
                $table->string('entity', 20)->default('leads');
                $table->string('trigger_type', 30)->default('manual');
                $table->foreignUuid('segment_id')->nullable()->constrained('crm_segments')->nullOnDelete();
                $table->json('exit_conditions')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'trigger_type', 'is_active']);
            });
        }

        if (! Schema::hasTable('crm_automation_steps')) {
            Schema::create('crm_automation_steps', function (Blueprint $table): void {
                $this->tenantKey($table);
                $table->foreignUuid('sequence_id')->constrained('crm_automation_sequences')->cascadeOnDelete();
                $table->unsignedInteger('position');
                $table->string('step_type', 20)->default('email');
                $table->unsignedInteger('delay_days')->default(0);
                $table->string('subject', 200)->nullable();
                $table->text('body')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['sequence_id', 'position']);
            });
        }

        if (! Schema::hasTable('crm_automation_enrollments')) {
            Schema::create('crm_automation_enrollments', function (Blueprint $table): void {
                $this->tenantKey($table);
                $table->foreignUuid('sequence_id')->constrained('crm_automation_sequences')->cascadeOnDelete();
                $table->uuid('contact_id')->nullable()->index();
                $table->uuid('lead_id')->nullable()->index();
                $table->string('status', 20)->default('active');
                $table->unsignedInteger('current_position')->default(0);
                $table->timestamp('next_run_at')->nullable();
                $table->string('exit_reason', 40)->nullable();
                $table->timestamp('enrolled_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'status', 'next_run_at']);
            });
        }

        if (! Schema::hasTable('crm_automation_step_logs')) {
            Schema::create('crm_automation_step_logs', function (Blueprint $table): void {
                $this->tenantKey($table);
                $table->foreignUuid('enrollment_id')->constrained('crm_automation_enrollments')->cascadeOnDelete();
                $table->uuid('step_id')->nullable();
                $table->unsignedInteger('position');
                $table->string('step_type', 20);
                $table->string('status', 20);
                $table->text('message')->nullable();
                $table->timestamp('executed_at');
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'enrollment_id']);
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'crm_automation_step_logs', 'crm_automation_enrollments', 'crm_automation_steps', 'crm_automation_sequences',
            'crm_quote_configurations', 'crm_discount_thresholds', 'crm_pricing_rules',
            'crm_mdf_claims', 'crm_mdf_funds', 'crm_deal_registrations', 'crm_partners', 'crm_partner_tiers',
            'crm_sales_quotas', 'crm_account_territories', 'crm_territories',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
