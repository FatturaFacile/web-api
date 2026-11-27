<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #473 (Mod 227) — foreign VAT refund management: the country refund rules (global rows with tenant_id null plus
 * company overrides), the fiscal representative mandates, the foreign VAT ledger, the refund claims (dossiers) with their
 * event trail, the account settings and the alert log of the deadline notifications. Dialect-agnostic (SQLite and PostgreSQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rules();
        $this->mandates();
        $this->settings();
        $this->claims();
        $this->ledger();
        $this->claimEvents();
        $this->alertLogs();
    }

    public function down(): void
    {
        foreach (['foreign_vat_alert_logs', 'vat_refund_claim_events', 'foreign_vat_ledger', 'vat_refund_claims', 'foreign_vat_settings', 'fiscal_representative_mandates', 'country_vat_refund_rules'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function rules(): void
    {
        if (Schema::hasTable('country_vat_refund_rules')) {
            return;
        }

        Schema::create('country_vat_refund_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('home_country_code', 2);
            $table->string('refund_country_code', 2);
            $table->string('regime_type', 30);
            $table->boolean('has_reciprocity')->default(true);
            $table->unsignedSmallInteger('expense_category_code');
            $table->string('sub_code', 20)->nullable();
            $table->decimal('deductible_percentage', 5, 2)->default(100);
            $table->decimal('min_annual_amount', 14, 2)->nullable();
            $table->decimal('min_quarterly_amount', 14, 2)->nullable();
            $table->string('currency_code', 3);
            $table->unsignedTinyInteger('filing_deadline_month');
            $table->unsignedTinyInteger('filing_deadline_day');
            $table->json('vat_rates')->nullable();
            $table->boolean('requires_fiscal_representative')->default(false);
            $table->boolean('requires_taxable_status_certificate')->default(false);
            $table->string('notes_key', 120)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'home_country_code', 'refund_country_code', 'expense_category_code'], 'cvrr_lookup_idx');
            $table->index(['refund_country_code', 'regime_type'], 'cvrr_regime_idx');
        });
    }

    private function mandates(): void
    {
        if (Schema::hasTable('fiscal_representative_mandates')) {
            return;
        }

        Schema::create('fiscal_representative_mandates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('country_code', 2);
            $table->string('representative_name', 190);
            $table->string('representative_tax_id', 60)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone', 60)->nullable();
            $table->date('mandate_start_date');
            $table->date('mandate_end_date')->nullable();
            $table->string('power_of_attorney_document_path', 500)->nullable();
            $table->string('certificate_of_taxable_status_path', 500)->nullable();
            $table->date('certificate_expiry_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'country_code', 'is_active'], 'frm_country_idx');
        });
    }

    private function settings(): void
    {
        if (Schema::hasTable('foreign_vat_settings')) {
            return;
        }

        Schema::create('foreign_vat_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->unique()->constrained('tenants')->cascadeOnDelete();
            $table->boolean('auto_intercept')->default(true);
            $table->uuid('receivable_account_id')->nullable();
            $table->uuid('non_recoverable_expense_account_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function claims(): void
    {
        if (Schema::hasTable('vat_refund_claims')) {
            return;
        }

        Schema::create('vat_refund_claims', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('claim_number', 40);
            $table->string('home_country_code', 2);
            $table->string('target_country_code', 2);
            $table->string('regime_type', 30);
            $table->string('period_type', 12);
            $table->unsignedSmallInteger('fiscal_year');
            $table->date('period_start_date');
            $table->date('period_end_date');
            $table->date('statutory_deadline_date');
            $table->string('currency_code', 3);
            $table->decimal('total_requested_amount', 14, 2)->default(0);
            $table->decimal('total_approved_amount', 14, 2)->default(0);
            $table->decimal('total_rejected_amount', 14, 2)->default(0);
            $table->decimal('total_refunded_amount', 14, 2)->default(0);
            $table->decimal('carried_base_amount', 14, 2)->default(0);
            $table->decimal('refund_exchange_rate', 14, 6)->nullable();
            $table->uuid('fiscal_representative_mandate_id')->nullable();
            $table->string('status', 30)->default('DRAFT');
            $table->timestamp('submission_date')->nullable();
            $table->string('protocol_number', 80)->nullable();
            $table->string('generated_xml_path', 500)->nullable();
            $table->string('generated_pdf_dossier_path', 500)->nullable();
            $table->string('zip_bundle_path', 500)->nullable();
            $table->uuid('bank_account_id')->nullable();
            $table->uuid('rejection_journal_entry_id')->nullable();
            $table->uuid('reconciliation_journal_entry_id')->nullable();
            $table->date('refunded_on')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'claim_number'], 'vrc_number_unique');
            $table->index(['tenant_id', 'status'], 'vrc_status_idx');
            $table->index(['tenant_id', 'target_country_code', 'fiscal_year'], 'vrc_country_year_idx');
        });
    }

    private function ledger(): void
    {
        if (Schema::hasTable('foreign_vat_ledger')) {
            return;
        }

        Schema::create('foreign_vat_ledger', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('source_type', 30);
            $table->uuid('source_id')->nullable();
            $table->string('document_number', 80);
            $table->date('document_date');
            $table->string('supplier_name', 190)->nullable();
            $table->string('supplier_vat_number', 40)->nullable();
            $table->string('supplier_country_code', 2);
            $table->unsignedSmallInteger('expense_category_code')->default(10);
            $table->decimal('net_amount', 14, 2)->default(0);
            $table->decimal('foreign_vat_rate', 7, 4)->default(0);
            $table->decimal('foreign_vat_amount', 14, 2)->default(0);
            $table->string('currency_code', 3);
            $table->decimal('exchange_rate_to_base', 14, 6)->default(1);
            $table->decimal('base_currency_foreign_vat_amount', 14, 2)->default(0);
            $table->decimal('eligible_percentage', 5, 2)->default(0);
            $table->decimal('recoverable_vat_amount', 14, 2)->default(0);
            $table->decimal('non_recoverable_vat_amount', 14, 2)->default(0);
            $table->decimal('base_recoverable_vat_amount', 14, 2)->default(0);
            $table->uuid('transitory_account_id')->nullable();
            $table->uuid('journal_entry_id')->nullable();
            $table->string('status', 30)->default('PENDING_REVIEW');
            $table->uuid('vat_refund_claim_id')->nullable();
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedTinyInteger('fiscal_quarter');
            $table->string('attachment_path', 500)->nullable();
            $table->string('posting_error', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'source_type', 'source_id'], 'fvl_source_unique');
            $table->index(['tenant_id', 'supplier_country_code', 'fiscal_year', 'status'], 'fvl_country_idx');
            $table->index(['vat_refund_claim_id'], 'fvl_claim_idx');
        });
    }

    private function claimEvents(): void
    {
        if (Schema::hasTable('vat_refund_claim_events')) {
            return;
        }

        Schema::create('vat_refund_claim_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('vat_refund_claim_id');
            $table->string('event', 40);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->text('note')->nullable();
            $table->json('payload')->nullable();
            $table->uuid('user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['vat_refund_claim_id', 'created_at'], 'vrce_claim_idx');
        });
    }

    private function alertLogs(): void
    {
        if (Schema::hasTable('foreign_vat_alert_logs')) {
            return;
        }

        Schema::create('foreign_vat_alert_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('alert_key', 160);
            $table->unsignedSmallInteger('level')->default(0);
            $table->timestamp('sent_at');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'alert_key', 'level'], 'fval_unique');
        });
    }
};
