<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Swiss VAT (MWST / IVA / TVA) compliance module (Mod 024, Issue #164):
 * - Company regime configuration (Effective vs Saldosteuersatz, agreed vs received, reporting frequency)
 * - Effective-dated Swiss VAT tax codes (8.1%, 2.6%, 3.8%, 0% exempt with/without credit, out-of-scope, historical 2018-2023)
 * - Periodic Swiss VAT returns with full AFC/ESTV boxes and accounting quadratura report
 * - Cross-border Bezugsteuer (acquisition tax on foreign services, Box 382/383)
 * - LIVA Art. 30-33 input tax adjustments (autoconsumo, degrassazione, mixed-use, subsidies)
 * - SuisseTax eCH-0217 XML export history and XSD validation audit log.
 * Dialect-agnostic: runs identically on SQLite and PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swiss_vat_configs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('uid_mwst', 32)->nullable();
            $table->string('accounting_method', 30)->default('effective'); // effective | saldosteuersatz
            $table->string('consideration_basis', 20)->default('agreed'); // agreed | received
            $table->string('reporting_frequency', 20)->default('quarterly'); // quarterly | semiannual | monthly
            $table->decimal('sss_rate_primary', 5, 2)->nullable();
            $table->decimal('sss_rate_secondary', 5, 2)->nullable();
            $table->string('sss_sector_primary', 120)->nullable();
            $table->string('sss_sector_secondary', 120)->nullable();
            $table->string('rounding_mode', 20)->default('chf_005'); // chf_005 | standard_001
            $table->boolean('auto_detect_bezugsteuer')->default(true);
            $table->decimal('bezugsteuer_threshold_chf', 15, 2)->default(10000.00);
            $table->string('default_currency', 3)->default('CHF');
            $table->string('canton', 4)->nullable();
            $table->json('gl_accounts')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id']);
        });

        Schema::create('swiss_vat_tax_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('rate_type', 30)->default('standard'); // standard | reduced | accommodation | zero_exempt | saldosteuersatz | bezugsteuer
            $table->string('exemption_type', 40)->default('none'); // none | exempt_with_deduction | exempt_without_deduction | out_of_scope
            $table->decimal('rate_percent', 5, 2)->default(0);
            $table->string('afc_box', 10)->default('303');
            $table->boolean('is_bezugsteuer')->default(false);
            $table->boolean('is_input_tax_deductible')->default(true);
            $table->string('sss_sector_code', 30)->nullable();
            $table->date('effective_from')->default('2024-01-01');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code', 'effective_from'], 'ch_vat_codes_unique');
            $table->index(['tenant_id', 'rate_type', 'is_active'], 'ch_vat_codes_lookup');
        });

        Schema::create('swiss_vat_returns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->string('period_type', 20)->default('quarterly'); // quarterly | semiannual | monthly
            $table->unsignedTinyInteger('period_number');
            $table->string('period_label', 20);
            $table->date('period_from');
            $table->date('period_to');
            $table->string('accounting_method', 30)->default('effective');
            $table->string('consideration_basis', 20)->default('agreed');
            $table->string('currency', 3)->default('CHF');
            $table->string('status', 20)->default('draft'); // draft | calculated | validated | finalized | submitted
            $table->decimal('box_200_total_turnover', 15, 2)->default(0);
            $table->decimal('box_289_total_deductions', 15, 2)->default(0);
            $table->decimal('box_299_taxable_turnover', 15, 2)->default(0);
            $table->decimal('box_399_total_output_tax', 15, 2)->default(0);
            $table->decimal('box_479_total_input_tax', 15, 2)->default(0);
            $table->decimal('box_500_payable_amount', 15, 2)->default(0);
            $table->decimal('box_510_credit_amount', 15, 2)->default(0);
            $table->decimal('gross_turnover', 15, 2)->default(0);
            $table->decimal('invoiced_vat_total', 15, 2)->default(0);
            $table->decimal('forfeited_input_vat', 15, 2)->default(0);
            $table->decimal('sss_revenue_adjustment', 15, 2)->default(0);
            $table->decimal('bezugsteuer_base', 15, 2)->default(0);
            $table->decimal('bezugsteuer_tax', 15, 2)->default(0);
            $table->decimal('adjustments_box_410', 15, 2)->default(0);
            $table->decimal('adjustments_box_415', 15, 2)->default(0);
            $table->decimal('adjustments_box_420', 15, 2)->default(0);
            $table->json('afc_boxes');
            $table->boolean('quadratura_passed')->default(false);
            $table->json('quadratura_report')->nullable();
            $table->json('reconciliation_lines')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->uuid('finalized_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('suissetax_reference', 80)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'fiscal_year', 'period_type', 'period_number'], 'ch_vat_returns_period_unique');
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('swiss_vat_bezugsteuer_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('swiss_vat_return_id')->nullable()->constrained('swiss_vat_returns')->nullOnDelete();
            $table->string('source_document_type', 50)->nullable();
            $table->uuid('source_document_id')->nullable();
            $table->uuid('supplier_id')->nullable();
            $table->string('supplier_name', 190);
            $table->string('supplier_country', 2);
            $table->string('supplier_vat_number', 50)->nullable();
            $table->string('invoice_number', 80)->nullable();
            $table->date('invoice_date');
            $table->string('service_category', 40)->default('other_cross_border_service');
            $table->string('description', 255);
            $table->string('currency', 3)->default('CHF');
            $table->decimal('original_net_amount', 15, 2)->default(0);
            $table->decimal('exchange_rate', 12, 6)->default(1);
            $table->decimal('net_amount_chf', 15, 2)->default(0);
            $table->decimal('vat_rate_percent', 5, 2)->default(8.10);
            $table->decimal('tax_amount_chf', 15, 2)->default(0);
            $table->decimal('deductible_input_tax_chf', 15, 2)->default(0);
            $table->string('afc_box', 10)->default('383');
            $table->string('detection_source', 20)->default('manual'); // auto_detected | manual
            $table->string('detection_reason', 255)->nullable();
            $table->string('status', 20)->default('detected'); // detected | confirmed | excluded | declared
            $table->uuid('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'invoice_date', 'status'], 'ch_bezugsteuer_period_idx');
            $table->index(['source_document_type', 'source_document_id'], 'ch_bezugsteuer_source_idx');
        });

        Schema::create('swiss_vat_input_tax_adjustments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('swiss_vat_return_id')->nullable()->constrained('swiss_vat_returns')->nullOnDelete();
            $table->string('adjustment_type', 30); // autoconsumo | degrassazione | mixed_use_reduction | subsidy_reduction
            $table->string('afc_box', 10); // 410 | 415 | 420
            $table->date('effective_date');
            $table->unsignedSmallInteger('fiscal_year');
            $table->string('asset_category', 30)->nullable(); // movable | real_estate | service | general
            $table->string('description', 255);
            $table->string('reference_document', 100)->nullable();
            $table->decimal('original_cost_chf', 15, 2)->nullable();
            $table->decimal('original_vat_rate', 5, 2)->nullable();
            $table->unsignedTinyInteger('years_elapsed')->nullable();
            $table->decimal('private_or_exempt_ratio', 5, 2)->nullable();
            $table->decimal('adjustment_amount_chf', 15, 2)->default(0);
            $table->string('legal_basis', 60);
            $table->string('status', 20)->default('draft'); // draft | approved | applied
            $table->text('notes')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'effective_date', 'status'], 'ch_adjustments_period_idx');
        });

        Schema::create('swiss_vat_xml_exports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('swiss_vat_return_id')->constrained('swiss_vat_returns')->cascadeOnDelete();
            $table->string('accounting_method', 30);
            $table->string('period_label', 20);
            $table->string('schema_version', 40)->default('eCH-0217-v2.0');
            $table->string('file_name', 180);
            $table->string('sha256_checksum', 64);
            $table->boolean('xsd_valid')->default(false);
            $table->boolean('quadratura_passed')->default(false);
            $table->json('validation_errors')->nullable();
            $table->longText('xml_payload');
            $table->uuid('exported_by')->nullable();
            $table->timestamp('exported_at');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'swiss_vat_return_id'], 'ch_xml_exports_return_idx');
        });
    }

    public function down(): void
    {
        foreach ([
            'swiss_vat_xml_exports',
            'swiss_vat_input_tax_adjustments',
            'swiss_vat_bezugsteuer_entries',
            'swiss_vat_returns',
            'swiss_vat_tax_codes',
            'swiss_vat_configs',
        ] as $tableName) {
            Schema::dropIfExists($tableName);
        }
    }
};
