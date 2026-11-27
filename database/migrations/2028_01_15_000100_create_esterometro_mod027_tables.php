<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #287 (Mod 027) — Italian Esterometro & Reverse Charge Automation:
 * self_invoice_settings, self_invoice_classification_rules, self_invoices, self_invoice_lines, self_invoice_sdi_logs.
 * Dialect-agnostic (SQLite and PostgreSQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->settings();
        $this->classificationRules();
        $this->selfInvoices();
        $this->selfInvoiceLines();
        $this->selfInvoiceSdiLogs();
    }

    public function down(): void
    {
        foreach ([
            'self_invoice_sdi_logs',
            'self_invoice_lines',
            'self_invoices',
            'self_invoice_classification_rules',
            'self_invoice_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function settings(): void
    {
        if (Schema::hasTable('self_invoice_settings')) {
            return;
        }

        Schema::create('self_invoice_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->unique()->constrained('tenants')->cascadeOnDelete();
            $table->string('auto_generate_mode', 20)->default('auto_draft'); // auto_draft, auto_validate, manual
            $table->boolean('auto_post_vat_registers')->default(true);
            $table->boolean('auto_post_journal')->default(true);
            $table->string('sales_vat_section_code', 20)->default('VEN-AUT');
            $table->string('purchase_vat_section_code', 20)->default('ACQ-RC');
            $table->string('numbering_prefix', 20)->default('AF-');
            $table->unsignedTinyInteger('numbering_padding')->default(5);
            $table->string('default_recipient_code', 7)->default('0000000');
            $table->boolean('use_tenant_sdi_code')->default(true);
            $table->string('signature_format', 20)->default('none'); // none, cades, xades
            $table->uuid('clearing_account_id')->nullable();
            $table->uuid('non_deductible_account_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function classificationRules(): void
    {
        if (Schema::hasTable('self_invoice_classification_rules')) {
            return;
        }

        Schema::create('self_invoice_classification_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('name', 160);
            $table->string('document_type', 4); // TD16..TD28
            $table->string('supplier_region', 20)->default('ANY'); // IT, EU, EXTRA_EU, SM, ANY
            $table->string('supplier_country_code', 2)->nullable();
            $table->string('transaction_nature', 30)->default('ANY'); // DOMESTIC_RC, SERVICES, GOODS, GOODS_IN_ITALY, REGULARIZATION, SAN_MARINO, ANY
            $table->string('source_vat_nature_prefix', 10)->nullable(); // N6.1..N6.9, N2, N3
            $table->decimal('default_vat_rate', 5, 2)->default(22.00);
            $table->string('default_vat_nature', 10)->nullable();
            $table->decimal('default_deductibility_percent', 5, 2)->default(100.00);
            $table->string('soggetto_emittente', 2)->default('CC');
            $table->unsignedSmallInteger('priority')->default(100);
            $table->string('legal_reference', 190)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active', 'priority'], 'sicr_lookup_idx');
            $table->index(['document_type', 'supplier_region'], 'sicr_type_region_idx');
        });
    }

    private function selfInvoices(): void
    {
        if (Schema::hasTable('self_invoices')) {
            return;
        }

        Schema::create('self_invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('passive_invoice_id')->nullable()->constrained('passive_invoices')->nullOnDelete();
            $table->foreignUuid('linked_fpa_document_id')->nullable()->constrained('fpa_documents')->nullOnDelete();
            $table->foreignUuid('supplier_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('document_type', 4); // TD16, TD17, TD18, TD19, TD20, TD28
            $table->string('suggested_document_type', 4)->nullable();
            $table->boolean('is_type_overridden')->default(false);
            $table->string('override_reason', 500)->nullable();
            $table->json('compliance_warnings')->nullable();
            $table->string('status', 30)->default('DRAFT');
            $table->string('sdi_status', 30)->default('DRAFT');
            $table->string('document_number', 40);
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedInteger('sequential_number');
            $table->string('sectional_code', 20)->default('VEN-AUT');
            $table->date('integration_date');
            $table->date('operation_date')->nullable();
            $table->date('receipt_date')->nullable();
            $table->date('transmission_deadline_date');
            $table->string('regularization_trigger', 40)->nullable(); // omitted_4_months, irregular_30_days, manual
            $table->string('supplier_country_code', 2);
            $table->string('supplier_region', 20);
            $table->string('supplier_name', 200);
            $table->string('supplier_vat_number', 40)->nullable();
            $table->string('supplier_fiscal_code', 30)->nullable();
            $table->json('supplier_address')->nullable();
            $table->string('original_invoice_number', 80)->nullable();
            $table->date('original_invoice_date')->nullable();
            $table->string('original_sdi_identifier', 64)->nullable();
            $table->string('original_currency', 3)->default('EUR');
            $table->decimal('original_net_amount', 15, 2)->default(0);
            $table->decimal('original_vat_amount', 15, 2)->default(0);
            $table->decimal('original_gross_amount', 15, 2)->default(0);
            $table->decimal('exchange_rate', 14, 6)->default(1);
            $table->date('exchange_rate_date')->nullable();
            $table->string('exchange_rate_source', 30)->default('EUR');
            $table->string('currency', 3)->default('EUR');
            $table->decimal('taxable_amount_eur', 15, 2)->default(0);
            $table->decimal('vat_amount_eur', 15, 2)->default(0);
            $table->decimal('deductible_vat_eur', 15, 2)->default(0);
            $table->decimal('non_deductible_vat_eur', 15, 2)->default(0);
            $table->decimal('gross_amount_eur', 15, 2)->default(0);
            $table->string('soggetto_emittente', 2)->default('CC');
            $table->string('recipient_code', 7)->default('0000000');
            $table->string('sdi_identificativo', 64)->nullable();
            $table->string('sdi_filename', 120)->nullable();
            $table->string('sdi_progressive', 10)->nullable();
            $table->longText('xml_content')->nullable();
            $table->string('xml_sha256', 64)->nullable();
            $table->string('signed_storage_path', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_code', 30)->nullable();
            $table->text('rejection_description')->nullable();
            $table->uuid('sales_vat_entry_id')->nullable();
            $table->uuid('purchase_vat_entry_id')->nullable();
            $table->uuid('journal_entry_id')->nullable();
            $table->boolean('is_vat_reconciled')->default(false);
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'document_number'], 'si_tenant_docnum_unique');
            $table->index(['tenant_id', 'status'], 'si_tenant_status_idx');
            $table->index(['tenant_id', 'document_type'], 'si_tenant_doctype_idx');
            $table->index(['tenant_id', 'transmission_deadline_date'], 'si_tenant_deadline_idx');
            $table->index(['tenant_id', 'passive_invoice_id'], 'si_tenant_passive_idx');
        });
    }

    private function selfInvoiceLines(): void
    {
        if (Schema::hasTable('self_invoice_lines')) {
            return;
        }

        Schema::create('self_invoice_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('self_invoice_id')->constrained('self_invoices')->cascadeOnDelete();
            $table->uuid('passive_invoice_line_id')->nullable();
            $table->unsignedSmallInteger('line_number')->default(1);
            $table->string('description', 1000);
            $table->string('transaction_nature', 30)->default('SERVICES');
            $table->decimal('quantity', 15, 4)->default(1);
            $table->decimal('unit_price_original', 15, 4)->default(0);
            $table->decimal('unit_price_eur', 15, 4)->default(0);
            $table->decimal('taxable_amount_original', 15, 2)->default(0);
            $table->decimal('taxable_amount_eur', 15, 2)->default(0);
            $table->string('source_vat_nature', 10)->nullable();
            $table->decimal('vat_rate', 5, 2)->default(22.00);
            $table->string('vat_nature', 10)->nullable();
            $table->decimal('vat_amount_eur', 15, 2)->default(0);
            $table->decimal('deductibility_percent', 5, 2)->default(100.00);
            $table->decimal('deductible_vat_eur', 15, 2)->default(0);
            $table->decimal('non_deductible_vat_eur', 15, 2)->default(0);
            $table->uuid('cost_account_id')->nullable();
            $table->string('normative_reference', 190)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'self_invoice_id'], 'sil_tenant_si_idx');
        });
    }

    private function selfInvoiceSdiLogs(): void
    {
        if (Schema::hasTable('self_invoice_sdi_logs')) {
            return;
        }

        Schema::create('self_invoice_sdi_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('self_invoice_id')->constrained('self_invoices')->cascadeOnDelete();
            $table->string('event_type', 30);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->string('sdi_identificativo', 64)->nullable();
            $table->string('sdi_filename', 120)->nullable();
            $table->string('error_code', 30)->nullable();
            $table->text('error_description')->nullable();
            $table->longText('raw_payload')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'self_invoice_id', 'created_at'], 'sisl_tenant_si_idx');
        });
    }
};
