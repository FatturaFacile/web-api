<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mod 231 — GDO trade terms: framework contracts with retail chains and buying groups (Italy and Switzerland), tiered
 * year-end rebates (PFA / Jahresrückvergütung), sell-in / sell-out trade promotions, monthly accrual runs and the final
 * settlement (credit note or promotional invoice reconciliation).
 * Dialect-agnostic: runs identically on SQLite and PostgreSQL. Every table carries the tenant, soft deletes and the audit columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->deliveryPoints();
        $this->contracts();
        $this->scales();
        $this->tiers();
        $this->promotions();
        $this->sellOutReports();
        $this->invoiceDeliveryPoints();
        $this->accrualRuns();
        $this->accrualLines();
        $this->settlements();
        $this->settlementLines();
    }

    public function down(): void
    {
        foreach (['gdt_settlement_lines', 'gdt_settlements', 'gdt_accrual_lines', 'gdt_accrual_runs', 'gdt_invoice_delivery_points', 'gdt_sell_out_reports', 'gdt_promotions', 'gdt_scale_tiers', 'gdt_scales', 'gdt_contracts', 'gdt_delivery_points'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function base(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
    }

    private function audit(Blueprint $table): void
    {
        $table->uuid('created_by')->nullable();
        $table->uuid('updated_by')->nullable();
        $table->uuid('deleted_by')->nullable();
        $table->timestamps();
        $table->softDeletes();
    }

    private function deliveryPoints(): void
    {
        if (Schema::hasTable('gdt_delivery_points')) {
            return;
        }

        Schema::create('gdt_delivery_points', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 160);
            $table->string('point_type', 20)->default('store');
            $table->string('city', 120)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 2)->default('IT');
            $table->boolean('is_active')->default(true);
            $this->audit($table);

            $table->index(['tenant_id', 'contact_id']);
            $table->index(['tenant_id', 'code']);
        });
    }

    private function contracts(): void
    {
        if (Schema::hasTable('gdt_contracts')) {
            return;
        }

        Schema::create('gdt_contracts', function (Blueprint $table): void {
            $this->base($table);
            $table->string('code', 40);
            $table->string('name', 160);
            $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->boolean('include_child_contacts')->default(true);
            $table->string('jurisdiction', 2)->default('IT');
            $table->string('currency', 3)->default('EUR');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 12)->default('draft');
            $table->decimal('discount_1_percent', 9, 4)->default(0);
            $table->decimal('discount_2_percent', 9, 4)->default(0);
            $table->decimal('discount_3_percent', 9, 4)->default(0);
            $table->decimal('cedi_discount_percent', 9, 4)->default(0);
            $table->decimal('pallet_discount_amount', 15, 4)->default(0);
            $table->decimal('tolerance_percent', 7, 4)->default(2);
            $table->uuid('expense_account_id')->nullable();
            $table->uuid('provision_account_id')->nullable();
            $table->uuid('payable_account_id')->nullable();
            $table->uuid('vat_account_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $this->audit($table);

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'contact_id']);
            $table->index(['tenant_id', 'code']);
        });
    }

    private function scales(): void
    {
        if (Schema::hasTable('gdt_scales')) {
            return;
        }

        Schema::create('gdt_scales', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('contract_id')->constrained('gdt_contracts')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 160);
            $table->string('tier_mode', 12)->default('retroactive');
            $table->string('basis', 10)->default('turnover');
            $table->string('volume_mode', 12)->default('total');
            $table->string('baseline_source', 14)->default('manual');
            $table->decimal('baseline_value', 18, 4)->default(0);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->text('category_ids')->nullable();
            $table->text('brand_ids')->nullable();
            $table->text('product_ids')->nullable();
            $table->text('delivery_point_ids')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $this->audit($table);

            $table->index(['tenant_id', 'contract_id']);
        });
    }

    private function tiers(): void
    {
        if (Schema::hasTable('gdt_scale_tiers')) {
            return;
        }

        Schema::create('gdt_scale_tiers', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('scale_id')->constrained('gdt_scales')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(1);
            $table->decimal('threshold', 18, 4);
            $table->decimal('rate_percent', 9, 4)->default(0);
            $table->decimal('flat_amount', 15, 4)->nullable();
            $table->string('label', 160)->nullable();
            $this->audit($table);

            $table->index(['scale_id', 'threshold']);
        });
    }

    private function promotions(): void
    {
        if (Schema::hasTable('gdt_promotions')) {
            return;
        }

        Schema::create('gdt_promotions', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('contract_id')->constrained('gdt_contracts')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 160);
            $table->string('promotion_type', 24);
            $table->string('date_basis', 10)->default('order');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('contribution_mode', 24)->default('percent_of_sales');
            $table->decimal('rate_percent', 9, 4)->nullable();
            $table->decimal('unit_amount', 15, 4)->nullable();
            $table->decimal('fixed_amount', 15, 4)->nullable();
            $table->string('fixed_accrual', 10)->default('linear');
            $table->decimal('cap_amount', 15, 4)->nullable();
            $table->text('category_ids')->nullable();
            $table->text('brand_ids')->nullable();
            $table->text('product_ids')->nullable();
            $table->text('delivery_point_ids')->nullable();
            $table->string('status', 12)->default('draft');
            $table->text('notes')->nullable();
            $this->audit($table);

            $table->index(['tenant_id', 'contract_id']);
            $table->index(['tenant_id', 'starts_on', 'ends_on']);
        });
    }

    private function sellOutReports(): void
    {
        if (Schema::hasTable('gdt_sell_out_reports')) {
            return;
        }

        Schema::create('gdt_sell_out_reports', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('contract_id')->constrained('gdt_contracts')->cascadeOnDelete();
            $table->foreignUuid('delivery_point_id')->nullable()->constrained('gdt_delivery_points')->nullOnDelete();
            $table->uuid('product_id')->index();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('quantity', 18, 4)->default(0);
            $table->decimal('amount', 18, 4)->default(0);
            $table->string('source', 16)->default('manual');
            $table->string('natural_key', 64);
            $table->timestamp('reported_at')->nullable();
            $this->audit($table);

            $table->unique(['tenant_id', 'natural_key']);
            $table->index(['tenant_id', 'contract_id', 'period_start']);
        });
    }

    private function invoiceDeliveryPoints(): void
    {
        if (Schema::hasTable('gdt_invoice_delivery_points')) {
            return;
        }

        Schema::create('gdt_invoice_delivery_points', function (Blueprint $table): void {
            $this->base($table);
            $table->uuid('invoice_id');
            $table->foreignUuid('delivery_point_id')->constrained('gdt_delivery_points')->cascadeOnDelete();
            $this->audit($table);

            $table->unique(['tenant_id', 'invoice_id']);
            $table->index('invoice_id');
        });
    }

    private function accrualRuns(): void
    {
        if (Schema::hasTable('gdt_accrual_runs')) {
            return;
        }

        Schema::create('gdt_accrual_runs', function (Blueprint $table): void {
            $this->base($table);
            $table->string('period_key', 7);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 12)->default('posted');
            // tenant:period while the run is posted, null once reversed: one live run per month and company.
            $table->string('unique_key', 80)->nullable()->unique();
            $table->decimal('total_accrued', 15, 4)->default(0);
            $table->unsignedInteger('contracts_count')->default(0);
            $table->uuid('journal_entry_id')->nullable();
            $table->uuid('reversal_journal_entry_id')->nullable();
            $table->uuid('run_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $this->audit($table);

            $table->index(['tenant_id', 'period_key']);
        });
    }

    private function accrualLines(): void
    {
        if (Schema::hasTable('gdt_accrual_lines')) {
            return;
        }

        Schema::create('gdt_accrual_lines', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('run_id')->constrained('gdt_accrual_runs')->cascadeOnDelete();
            $table->foreignUuid('contract_id')->constrained('gdt_contracts')->cascadeOnDelete();
            $table->string('source_type', 12);
            $table->uuid('source_id');
            $table->string('source_label', 200)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->decimal('base_value', 18, 4)->default(0);
            $table->decimal('earned_cumulative', 15, 4)->default(0);
            $table->decimal('previously_accrued', 15, 4)->default(0);
            $table->decimal('delta_amount', 15, 4)->default(0);
            $table->decimal('applied_rate_percent', 9, 4)->nullable();
            $table->string('status', 12)->default('accrued');
            $table->uuid('settlement_line_id')->nullable()->index();
            $this->audit($table);

            $table->index(['tenant_id', 'source_type', 'source_id']);
            $table->index(['tenant_id', 'contract_id']);
        });
    }

    private function settlements(): void
    {
        if (Schema::hasTable('gdt_settlements')) {
            return;
        }

        Schema::create('gdt_settlements', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('contract_id')->constrained('gdt_contracts')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('kind', 12)->default('year_end');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 12)->default('proposed');
            $table->string('tax_treatment', 40);
            $table->string('currency', 3)->default('EUR');
            $table->decimal('accrued_total', 15, 4)->default(0);
            $table->decimal('earned_total', 15, 4)->default(0);
            $table->decimal('variance_total', 15, 4)->default(0);
            $table->decimal('credit_note_amount', 15, 4)->default(0);
            $table->decimal('promo_amount', 15, 4)->default(0);
            $table->decimal('tax_rate', 7, 4)->nullable();
            $table->uuid('credit_note_id')->nullable()->index();
            $table->string('claim_invoice_number', 60)->nullable();
            $table->date('claim_invoice_date')->nullable();
            $table->decimal('claim_net', 15, 4)->nullable();
            $table->decimal('claim_tax', 15, 4)->nullable();
            $table->decimal('claim_variance', 15, 4)->nullable();
            $table->text('dispute_reason')->nullable();
            $table->uuid('release_journal_entry_id')->nullable();
            $table->uuid('set_off_journal_entry_id')->nullable();
            $table->date('settled_on')->nullable();
            $table->text('notes')->nullable();
            $this->audit($table);

            $table->index(['tenant_id', 'contract_id', 'status']);
            $table->index(['tenant_id', 'code']);
        });
    }

    private function settlementLines(): void
    {
        if (Schema::hasTable('gdt_settlement_lines')) {
            return;
        }

        Schema::create('gdt_settlement_lines', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('settlement_id')->constrained('gdt_settlements')->cascadeOnDelete();
            $table->string('source_type', 12);
            $table->uuid('source_id');
            $table->string('source_label', 200)->nullable();
            $table->string('treatment', 16);
            $table->decimal('base_value', 18, 4)->default(0);
            $table->decimal('accrued_amount', 15, 4)->default(0);
            $table->decimal('earned_amount', 15, 4)->default(0);
            $table->decimal('variance_amount', 15, 4)->default(0);
            $table->decimal('cumulative_earned', 15, 4)->default(0);
            $table->string('status', 12)->default('open');
            $table->text('notes')->nullable();
            $this->audit($table);

            $table->index(['tenant_id', 'settlement_id']);
            $table->index(['tenant_id', 'source_type', 'source_id']);
        });
    }
};
