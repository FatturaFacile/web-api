<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Corporate performance management: analytical cost accounting (cost centers, jobs, analytical ledger, overhead
     * allocation), the BI margin and financial read-models, and multi-version budgets with rolling forecasts. New
     * tables only, dialect-agnostic.
     */
    public function up(): void
    {
        $base = static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
        };

        if (! Schema::hasTable('analytical_cost_centers')) {
            Schema::create('analytical_cost_centers', function (Blueprint $table) use ($base): void {
                $base($table);
                $table->uuid('parent_id')->nullable()->index();
                $table->string('code', 40);
                $table->string('name', 160);
                $table->string('center_type', 24)->default('production');
                $table->uuid('responsible_user_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->date('valid_from')->nullable();
                $table->date('valid_to')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->uuid('deleted_by')->nullable();
                $table->unique(['tenant_id', 'code']);
            });
        }

        if (! Schema::hasTable('analytical_jobs')) {
            Schema::create('analytical_jobs', function (Blueprint $table) use ($base): void {
                $base($table);
                $table->string('code', 40);
                $table->string('name', 160);
                $table->uuid('customer_id')->nullable()->index();
                $table->uuid('sales_order_id')->nullable()->index();
                $table->foreignUuid('cost_center_id')->nullable()->constrained('analytical_cost_centers')->nullOnDelete();
                $table->string('status', 16)->default('planned');
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->decimal('contract_value', 18, 4)->default(0);
                $table->decimal('estimated_cost_budget', 18, 4)->default(0);
                $table->timestamps();
                $table->softDeletes();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->uuid('deleted_by')->nullable();
                $table->unique(['tenant_id', 'code']);
            });
        }

        if (! Schema::hasTable('cost_allocation_runs')) {
            Schema::create('cost_allocation_runs', function (Blueprint $table) use ($base): void {
                $base($table);
                $table->unsignedSmallInteger('fiscal_year');
                $table->unsignedTinyInteger('fiscal_month');
                $table->string('status', 12)->default('draft');
                $table->decimal('total_allocated_amount', 18, 4)->default(0);
                $table->uuid('executed_by')->nullable();
                $table->timestamp('executed_at')->nullable();
                $table->timestamp('reversed_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->uuid('deleted_by')->nullable();
                $table->index(['tenant_id', 'fiscal_year', 'fiscal_month']);
            });
        }

        if (! Schema::hasTable('cost_allocation_rules')) {
            Schema::create('cost_allocation_rules', function (Blueprint $table) use ($base): void {
                $base($table);
                $table->string('name', 160);
                $table->foreignUuid('source_cost_center_id')->constrained('analytical_cost_centers')->cascadeOnDelete();
                $table->string('driver_type', 24);
                $table->unsignedSmallInteger('step_order')->default(1);
                // Recipients: list of {type: cost_center|job, id, weight}. The weight is a percentage for
                // fixed_percentage, else a static driver quantity (headcount, square meters, machine hours).
                $table->json('recipients');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->uuid('deleted_by')->nullable();
                $table->index(['tenant_id', 'step_order']);
            });
        }

        if (! Schema::hasTable('analytical_ledger_entries')) {
            Schema::create('analytical_ledger_entries', function (Blueprint $table) use ($base): void {
                $base($table);
                $table->date('posting_date');
                $table->unsignedSmallInteger('fiscal_year');
                $table->unsignedTinyInteger('fiscal_month');
                $table->string('source_type', 24);
                $table->uuid('source_id')->nullable();
                $table->string('account_code', 40)->nullable();
                $table->string('nature_type', 24);
                $table->foreignUuid('cost_center_id')->nullable()->constrained('analytical_cost_centers')->nullOnDelete();
                $table->foreignUuid('job_id')->nullable()->constrained('analytical_jobs')->nullOnDelete();
                $table->uuid('product_id')->nullable();
                $table->uuid('product_family_id')->nullable();
                $table->uuid('customer_id')->nullable();
                $table->uuid('sales_agent_id')->nullable();
                $table->decimal('amount', 18, 4);
                $table->decimal('quantity', 18, 4)->default(0);
                $table->string('description', 255)->nullable();
                $table->boolean('is_allocated')->default(false);
                $table->foreignUuid('allocation_run_id')->nullable()->constrained('cost_allocation_runs')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->uuid('deleted_by')->nullable();
                $table->index(['tenant_id', 'fiscal_year', 'fiscal_month']);
                $table->index(['tenant_id', 'cost_center_id']);
                $table->index(['tenant_id', 'job_id']);
                $table->index(['tenant_id', 'source_type', 'source_id']);
            });
        }

        if (! Schema::hasTable('bi_sales_margin_facts')) {
            Schema::create('bi_sales_margin_facts', function (Blueprint $table) use ($base): void {
                $base($table);
                $table->date('posting_date');
                $table->unsignedSmallInteger('fiscal_year');
                $table->unsignedTinyInteger('fiscal_month');
                $table->string('document_type', 16)->default('invoice');
                $table->uuid('source_id')->nullable();
                $table->uuid('product_id')->nullable()->index();
                $table->uuid('product_family_id')->nullable()->index();
                $table->uuid('customer_id')->nullable()->index();
                $table->string('country_code', 2)->nullable();
                $table->string('region_code', 16)->nullable();
                $table->string('province_code', 16)->nullable();
                $table->uuid('sales_agent_id')->nullable()->index();
                $table->uuid('production_department_id')->nullable()->index();
                $table->foreignUuid('cost_center_id')->nullable()->constrained('analytical_cost_centers')->nullOnDelete();
                $table->foreignUuid('job_id')->nullable()->constrained('analytical_jobs')->nullOnDelete();
                $table->decimal('quantity_sold', 18, 4)->default(0);
                $table->decimal('gross_revenue', 18, 4)->default(0);
                $table->decimal('discount_amount', 18, 4)->default(0);
                $table->decimal('net_revenue', 18, 4)->default(0);
                $table->decimal('direct_cogs_amount', 18, 4)->default(0);
                $table->decimal('allocated_overhead_amount', 18, 4)->default(0);
                $table->decimal('commission_amount', 18, 4)->default(0);
                $table->decimal('logistics_cost_amount', 18, 4)->default(0);
                $table->decimal('first_contribution_margin', 18, 4)->default(0);
                $table->decimal('second_contribution_margin', 18, 4)->default(0);
                $table->decimal('full_industrial_margin', 18, 4)->default(0);
                $table->timestamps();
                $table->softDeletes();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->uuid('deleted_by')->nullable();
                $table->index(['tenant_id', 'posting_date']);
                $table->index(['tenant_id', 'fiscal_year', 'fiscal_month']);
            });
        }

        if (! Schema::hasTable('bi_financial_snapshots')) {
            Schema::create('bi_financial_snapshots', function (Blueprint $table) use ($base): void {
                $base($table);
                $table->date('snapshot_date');
                $table->unsignedSmallInteger('fiscal_year');
                $table->unsignedTinyInteger('fiscal_month');
                $table->decimal('accounts_receivable_balance', 18, 4)->default(0);
                $table->decimal('overdue_receivables_balance', 18, 4)->default(0);
                $table->decimal('accounts_payable_balance', 18, 4)->default(0);
                $table->decimal('inventory_valuation_balance', 18, 4)->default(0);
                $table->decimal('cash_and_bank_balance', 18, 4)->default(0);
                $table->decimal('net_working_capital', 18, 4)->default(0);
                $table->decimal('credit_sales_period', 18, 4)->default(0);
                $table->decimal('credit_purchases_period', 18, 4)->default(0);
                $table->decimal('cogs_period', 18, 4)->default(0);
                $table->timestamps();
                $table->softDeletes();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->uuid('deleted_by')->nullable();
                $table->unique(['tenant_id', 'snapshot_date']);
            });
        }

        if (! Schema::hasTable('controlling_budget_plans')) {
            Schema::create('controlling_budget_plans', function (Blueprint $table) use ($base): void {
                $base($table);
                $table->string('code', 40);
                $table->string('name', 160);
                $table->unsignedSmallInteger('fiscal_year');
                $table->string('plan_type', 20)->default('annual_budget');
                $table->unsignedSmallInteger('version_number')->default(1);
                $table->uuid('parent_plan_id')->nullable()->index();
                $table->unsignedTinyInteger('cutoff_month')->nullable();
                $table->string('status', 12)->default('draft');
                $table->string('currency_code', 3)->default('EUR');
                $table->uuid('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->uuid('deleted_by')->nullable();
                $table->index(['tenant_id', 'fiscal_year', 'plan_type']);
            });
        }

        if (! Schema::hasTable('controlling_budget_lines')) {
            Schema::create('controlling_budget_lines', function (Blueprint $table) use ($base): void {
                $base($table);
                $table->foreignUuid('plan_id')->constrained('controlling_budget_plans')->cascadeOnDelete();
                $table->string('account_code', 40)->nullable();
                $table->string('nature_type', 24);
                $table->foreignUuid('cost_center_id')->nullable()->constrained('analytical_cost_centers')->nullOnDelete();
                $table->foreignUuid('job_id')->nullable()->constrained('analytical_jobs')->nullOnDelete();
                $table->uuid('product_family_id')->nullable();
                $table->uuid('customer_id')->nullable();
                $table->string('country_code', 2)->nullable();
                $table->string('region_code', 16)->nullable();
                $table->uuid('sales_agent_id')->nullable();
                $table->uuid('production_department_id')->nullable();
                foreach (range(1, 12) as $month) {
                    $table->decimal(sprintf('month_%02d', $month), 18, 4)->default(0);
                }
                $table->decimal('annual_total', 18, 4)->default(0);
                $table->decimal('target_quantity', 18, 4)->default(0);
                $table->decimal('target_amount', 18, 4)->default(0);
                $table->decimal('target_margin_percent', 8, 4)->nullable();
                $table->string('distribution_driver', 24)->default('equal_12');
                $table->timestamps();
                $table->softDeletes();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->uuid('deleted_by')->nullable();
                $table->index(['tenant_id', 'plan_id']);
            });
        }
    }

    public function down(): void
    {
        foreach (['controlling_budget_lines', 'controlling_budget_plans', 'bi_financial_snapshots', 'bi_sales_margin_facts', 'analytical_ledger_entries', 'cost_allocation_rules', 'cost_allocation_runs', 'analytical_jobs', 'analytical_cost_centers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
