<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mod 020 (#167) Fixed Assets & Parallel CH/IT Depreciation enhancements:
 * - Global + tenant-overridable official fiscal depreciation rate tables (`eam_fiscal_depreciation_rates`)
 *   for Swiss AFC/ESTV (Merkblatt A 1995) and Italian D.M. 31/12/1988.
 * - Direct capital improvements & extraordinary capitalized maintenance ledger (`eam_asset_improvements`).
 * - Registry invoice/incentive fields on `eam_fixed_assets` (Transition 4.0 / 5.0, immediate expensing).
 * - Multi-book fiscal regime flags on `eam_asset_books` (CH AFC pure declining/linear/immediate, IT 50% first year).
 * - Multi-book disposal fields on `eam_asset_disposals` (sales invoice link, fiscal gain/loss).
 * - Capital good flag & asset category hint on `passive_invoice_lines` for automatic invoice-to-asset creation.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('eam_fiscal_depreciation_rates')) {
            Schema::create('eam_fiscal_depreciation_rates', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('jurisdiction', 5)->index();
                $table->string('regime_code', 40);
                $table->string('sector_code', 40);
                $table->string('sector_name', 180);
                $table->string('category_code', 60)->index();
                $table->string('category_name', 200);
                $table->decimal('declining_rate_percent', 7, 4)->nullable();
                $table->decimal('linear_rate_percent', 7, 4)->nullable();
                $table->boolean('first_year_half_rate')->default(false);
                $table->boolean('immediate_expensing_allowed')->default(false);
                $table->decimal('immediate_expensing_threshold', 15, 2)->nullable();
                $table->string('legal_reference', 255)->nullable();
                $table->json('translations')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'jurisdiction', 'sector_code'], 'eam_fiscal_rates_tenant_lookup');
                $table->index(['jurisdiction', 'sector_code', 'is_active'], 'eam_fiscal_rates_sector_lookup');
            });
        }

        if (! Schema::hasTable('eam_asset_improvements')) {
            Schema::create('eam_asset_improvements', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('asset_id')->constrained('eam_fixed_assets')->cascadeOnDelete();
                $table->string('improvement_number', 60)->index();
                $table->date('improvement_date');
                $table->decimal('amount', 15, 2);
                $table->string('description', 500);
                $table->uuid('supplier_id')->nullable();
                $table->uuid('purchase_invoice_id')->nullable();
                $table->string('purchase_invoice_number', 100)->nullable();
                $table->unsignedInteger('extends_useful_life_months')->default(0);
                $table->boolean('affects_tax_book')->default(true);
                $table->string('status', 30)->default('completed');
                $table->uuid('journal_entry_id')->nullable();
                $table->uuid('created_by_user_id')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'asset_id', 'improvement_date'], 'eam_asset_improvements_lookup');
            });
        }

        Schema::table('eam_fixed_assets', function (Blueprint $table): void {
            $this->addColumn($table, 'eam_fixed_assets', 'purchase_invoice_number', fn () => $table->string('purchase_invoice_number', 100)->nullable());
            $this->addColumn($table, 'eam_fixed_assets', 'fiscal_jurisdiction', fn () => $table->string('fiscal_jurisdiction', 5)->nullable());
            $this->addColumn($table, 'eam_fixed_assets', 'fiscal_rate_id', fn () => $table->uuid('fiscal_rate_id')->nullable()->index());
            $this->addColumn($table, 'eam_fixed_assets', 'fiscal_sector_code', fn () => $table->string('fiscal_sector_code', 40)->nullable());
            $this->addColumn($table, 'eam_fixed_assets', 'incentive_regime', fn () => $table->string('incentive_regime', 40)->default('none'));
            $this->addColumn($table, 'eam_fixed_assets', 'incentive_rate_percent', fn () => $table->decimal('incentive_rate_percent', 7, 4)->nullable());
            $this->addColumn($table, 'eam_fixed_assets', 'incentive_installment_years', fn () => $table->unsignedTinyInteger('incentive_installment_years')->nullable());
            $this->addColumn($table, 'eam_fixed_assets', 'incentive_interconnection_date', fn () => $table->date('incentive_interconnection_date')->nullable());
            $this->addColumn($table, 'eam_fixed_assets', 'incentive_tax_credit_total', fn () => $table->decimal('incentive_tax_credit_total', 15, 2)->nullable());
            $this->addColumn($table, 'eam_fixed_assets', 'incentive_extra_deduction_base', fn () => $table->decimal('incentive_extra_deduction_base', 15, 2)->nullable());
            $this->addColumn($table, 'eam_fixed_assets', 'immediate_expensing_applied', fn () => $table->boolean('immediate_expensing_applied')->default(false));
        });

        Schema::table('eam_asset_books', function (Blueprint $table): void {
            $this->addColumn($table, 'eam_asset_books', 'fiscal_regime', fn () => $table->string('fiscal_regime', 40)->nullable());
            $this->addColumn($table, 'eam_asset_books', 'fiscal_rate_id', fn () => $table->uuid('fiscal_rate_id')->nullable());
            $this->addColumn($table, 'eam_asset_books', 'switch_to_straight_line', fn () => $table->boolean('switch_to_straight_line')->default(true));
            $this->addColumn($table, 'eam_asset_books', 'immediate_expensing', fn () => $table->boolean('immediate_expensing')->default(false));
            $this->addColumn($table, 'eam_asset_books', 'first_year_half_rate', fn () => $table->boolean('first_year_half_rate')->default(false));
            $this->addColumn($table, 'eam_asset_books', 'pro_memoria_value', fn () => $table->decimal('pro_memoria_value', 15, 2)->nullable());
            $this->addColumn($table, 'eam_asset_books', 'impairment_loss_total', fn () => $table->decimal('impairment_loss_total', 15, 2)->default(0));
        });

        Schema::table('eam_asset_disposals', function (Blueprint $table): void {
            $this->addColumn($table, 'eam_asset_disposals', 'sales_invoice_id', fn () => $table->uuid('sales_invoice_id')->nullable()->index());
            $this->addColumn($table, 'eam_asset_disposals', 'disposal_document_number', fn () => $table->string('disposal_document_number', 100)->nullable());
            $this->addColumn($table, 'eam_asset_disposals', 'fiscal_carrying_amount_at_disposal', fn () => $table->decimal('fiscal_carrying_amount_at_disposal', 15, 2)->nullable());
            $this->addColumn($table, 'eam_asset_disposals', 'fiscal_gain_loss_amount', fn () => $table->decimal('fiscal_gain_loss_amount', 15, 2)->nullable());
            $this->addColumn($table, 'eam_asset_disposals', 'fiscal_gain_spread_years', fn () => $table->unsignedTinyInteger('fiscal_gain_spread_years')->nullable());
        });

        Schema::table('passive_invoice_lines', function (Blueprint $table): void {
            $this->addColumn($table, 'passive_invoice_lines', 'is_capital_asset', fn () => $table->boolean('is_capital_asset')->default(false));
            $this->addColumn($table, 'passive_invoice_lines', 'asset_category_id', fn () => $table->uuid('asset_category_id')->nullable());
        });
    }

    public function down(): void
    {
        // Non-destructive enterprise migration policy: fiscal history tables and columns are preserved.
    }

    private function addColumn(Blueprint $table, string $tableName, string $column, Closure $definition): void
    {
        if (! Schema::hasColumn($tableName, $column)) {
            $definition();
        }
    }
};
