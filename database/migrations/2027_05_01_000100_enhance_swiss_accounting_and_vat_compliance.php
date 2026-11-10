<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gl_ledger_settings')) {
            Schema::table('gl_ledger_settings', function (Blueprint $table): void {
                if (! Schema::hasColumn('gl_ledger_settings', 'fx_unrealised_gain_provision_account_id')) {
                    $table->foreignUuid('fx_unrealised_gain_provision_account_id')
                        ->nullable()
                        ->constrained('chart_of_accounts')
                        ->nullOnDelete();
                }
                if (! Schema::hasColumn('gl_ledger_settings', 'enforce_swiss_imparity_principle')) {
                    $table->boolean('enforce_swiss_imparity_principle')->default(true);
                }
                if (! Schema::hasColumn('gl_ledger_settings', 'unrealised_gain_treatment')) {
                    $table->string('unrealised_gain_treatment', 30)->nullable();
                }
            });
        }

        if (Schema::hasTable('gl_fx_revaluations')) {
            Schema::table('gl_fx_revaluations', function (Blueprint $table): void {
                if (! Schema::hasColumn('gl_fx_revaluations', 'imparity_deferred')) {
                    $table->boolean('imparity_deferred')->default(false);
                }
                if (! Schema::hasColumn('gl_fx_revaluations', 'treatment_applied')) {
                    $table->string('treatment_applied', 30)->nullable();
                }
            });
        }

        if (! Schema::hasTable('gl_swiss_statutory_notes')) {
            Schema::create('gl_swiss_statutory_notes', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable();
                $table->foreignUuid('fiscal_year_id')
                    ->constrained('fiscal_years')
                    ->cascadeOnDelete();
                $table->string('fte_band', 30)->default('up_to_10');
                $table->text('valuation_principles')->nullable();
                $table->decimal('pledged_assets_amount', 15, 4)->default(0);
                $table->text('pledged_assets_details')->nullable();
                $table->decimal('leasing_commitments_amount', 15, 4)->default(0);
                $table->text('leasing_commitments_details')->nullable();
                $table->decimal('pension_fund_liabilities_amount', 15, 4)->nullable();
                $table->text('pension_fund_liabilities_details')->nullable();
                $table->jsonb('fire_insurance_values')->nullable();
                $table->text('subsequent_events')->nullable();
                $table->decimal('auditor_fees_audit', 15, 4)->default(0);
                $table->decimal('auditor_fees_other', 15, 4)->default(0);
                $table->jsonb('extra_disclosures')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'fiscal_year_id'], 'uq_gl_swiss_statutory_notes_tenant_fy');
            });
        }

        if (Schema::hasTable('fiscal_years')) {
            Schema::table('fiscal_years', function (Blueprint $table): void {
                if (! Schema::hasColumn('fiscal_years', 'reopened_at')) {
                    $table->timestamp('reopened_at')->nullable();
                }
                if (! Schema::hasColumn('fiscal_years', 'reopened_by')) {
                    $table->uuid('reopened_by')->nullable();
                }
                if (! Schema::hasColumn('fiscal_years', 'reopen_reason')) {
                    $table->string('reopen_reason', 500)->nullable();
                }
                if (! Schema::hasColumn('fiscal_years', 'agm_approved_at')) {
                    $table->timestamp('agm_approved_at')->nullable();
                }
                if (! Schema::hasColumn('fiscal_years', 'agm_approved_by')) {
                    $table->uuid('agm_approved_by')->nullable();
                }
                if (! Schema::hasColumn('fiscal_years', 'agm_resolution_reference')) {
                    $table->string('agm_resolution_reference', 190)->nullable();
                }
            });
        }

        if (Schema::hasTable('vat_tax_settings')) {
            Schema::table('vat_tax_settings', function (Blueprint $table): void {
                if (! Schema::hasColumn('vat_tax_settings', 'regime')) {
                    $table->string('regime', 30)->default('effective');
                }
                if (! Schema::hasColumn('vat_tax_settings', 'sss_rate_1')) {
                    $table->decimal('sss_rate_1', 5, 2)->nullable();
                }
                if (! Schema::hasColumn('vat_tax_settings', 'sss_rate_2')) {
                    $table->decimal('sss_rate_2', 5, 2)->nullable();
                }
                if (! Schema::hasColumn('vat_tax_settings', 'flat_tax_rates')) {
                    $table->jsonb('flat_tax_rates')->nullable();
                }
                if (! Schema::hasColumn('vat_tax_settings', 'flat_rate_adjustment_account_id')) {
                    $table->foreignUuid('flat_rate_adjustment_account_id')
                        ->nullable()
                        ->constrained('chart_of_accounts')
                        ->nullOnDelete();
                }
                if (! Schema::hasColumn('vat_tax_settings', 'acquisition_tax_account_id')) {
                    $table->foreignUuid('acquisition_tax_account_id')
                        ->nullable()
                        ->constrained('chart_of_accounts')
                        ->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('vat_codes')) {
            Schema::table('vat_codes', function (Blueprint $table): void {
                if (! Schema::hasColumn('vat_codes', 'sss_rate_percent')) {
                    $table->decimal('sss_rate_percent', 5, 2)->nullable();
                }
                if (! Schema::hasColumn('vat_codes', 'estv_box')) {
                    $table->string('estv_box', 10)->nullable();
                }
            });
        }

        if (Schema::hasTable('vat_settlements')) {
            Schema::table('vat_settlements', function (Blueprint $table): void {
                if (! Schema::hasColumn('vat_settlements', 'regime')) {
                    $table->string('regime', 30)->default('effective');
                }
                if (! Schema::hasColumn('vat_settlements', 'gross_turnover')) {
                    $table->decimal('gross_turnover', 15, 2)->default(0);
                }
                if (! Schema::hasColumn('vat_settlements', 'exempt_turnover')) {
                    $table->decimal('exempt_turnover', 15, 2)->default(0);
                }
                if (! Schema::hasColumn('vat_settlements', 'acquisition_tax_base')) {
                    $table->decimal('acquisition_tax_base', 15, 2)->default(0);
                }
                if (! Schema::hasColumn('vat_settlements', 'acquisition_tax_amount')) {
                    $table->decimal('acquisition_tax_amount', 15, 4)->default(0);
                }
                if (! Schema::hasColumn('vat_settlements', 'gross_input_vat_forfeited')) {
                    $table->decimal('gross_input_vat_forfeited', 15, 4)->default(0);
                }
                if (! Schema::hasColumn('vat_settlements', 'flat_rate_adjustment_amount')) {
                    $table->decimal('flat_rate_adjustment_amount', 15, 4)->default(0);
                }
                if (! Schema::hasColumn('vat_settlements', 'estv_boxes')) {
                    $table->jsonb('estv_boxes')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('vat_settlements')) {
            Schema::table('vat_settlements', function (Blueprint $table): void {
                $columns = array_filter([
                    'regime',
                    'gross_turnover',
                    'exempt_turnover',
                    'acquisition_tax_base',
                    'acquisition_tax_amount',
                    'gross_input_vat_forfeited',
                    'flat_rate_adjustment_amount',
                    'estv_boxes',
                ], fn (string $col): bool => Schema::hasColumn('vat_settlements', $col));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        if (Schema::hasTable('vat_codes')) {
            Schema::table('vat_codes', function (Blueprint $table): void {
                $columns = array_filter([
                    'sss_rate_percent',
                    'estv_box',
                ], fn (string $col): bool => Schema::hasColumn('vat_codes', $col));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        if (Schema::hasTable('vat_tax_settings')) {
            Schema::table('vat_tax_settings', function (Blueprint $table): void {
                if (Schema::hasColumn('vat_tax_settings', 'flat_rate_adjustment_account_id')) {
                    $table->dropConstrainedForeignId('flat_rate_adjustment_account_id');
                }
                if (Schema::hasColumn('vat_tax_settings', 'acquisition_tax_account_id')) {
                    $table->dropConstrainedForeignId('acquisition_tax_account_id');
                }
                $columns = array_filter([
                    'regime',
                    'sss_rate_1',
                    'sss_rate_2',
                    'flat_tax_rates',
                ], fn (string $col): bool => Schema::hasColumn('vat_tax_settings', $col));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        if (Schema::hasTable('fiscal_years')) {
            Schema::table('fiscal_years', function (Blueprint $table): void {
                $columns = array_filter([
                    'reopened_at',
                    'reopened_by',
                    'reopen_reason',
                    'agm_approved_at',
                    'agm_approved_by',
                    'agm_resolution_reference',
                ], fn (string $col): bool => Schema::hasColumn('fiscal_years', $col));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        Schema::dropIfExists('gl_swiss_statutory_notes');

        if (Schema::hasTable('gl_fx_revaluations')) {
            Schema::table('gl_fx_revaluations', function (Blueprint $table): void {
                $columns = array_filter([
                    'imparity_deferred',
                    'treatment_applied',
                ], fn (string $col): bool => Schema::hasColumn('gl_fx_revaluations', $col));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        if (Schema::hasTable('gl_ledger_settings')) {
            Schema::table('gl_ledger_settings', function (Blueprint $table): void {
                if (Schema::hasColumn('gl_ledger_settings', 'fx_unrealised_gain_provision_account_id')) {
                    $table->dropConstrainedForeignId('fx_unrealised_gain_provision_account_id');
                }
                $columns = array_filter([
                    'enforce_swiss_imparity_principle',
                    'unrealised_gain_treatment',
                ], fn (string $col): bool => Schema::hasColumn('gl_ledger_settings', $col));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
