<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mod 356 — Withholding taxes on commissions, royalties, occasional work and amateur sports (CU / F24 / AVS).
 * Tenant isolation is enforced by the BelongsToTenant global scope (every table has a nullable tenant_id, like the rest of
 * the schema). Dialect-agnostic: runs identically on SQLite and PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->profiles();
        $this->certificates();
        $this->inlNotifications();
        $this->settlements();
        $this->cuRecords();
    }

    public function down(): void
    {
        foreach (['fiscal_cu_autonomous_records', 'fiscal_recipient_settlements', 'fiscal_occasional_inl_notifications', 'fiscal_agent_reduced_withholding_certs', 'fiscal_recipient_profiles'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function audit(Blueprint $table): void
    {
        $table->uuid('created_by')->nullable();
        $table->uuid('updated_by')->nullable();
        $table->uuid('deleted_by')->nullable();
        $table->timestamps();
        $table->softDeletes();
    }

    private function profiles(): void
    {
        if (Schema::hasTable('fiscal_recipient_profiles')) {
            return;
        }

        Schema::create('fiscal_recipient_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('display_name', 200);
            $table->string('regime', 40); // agent_25bis, author_royalty, occasional_worker, amateur_sports, swiss_foreign_artist, swiss_de_minimis
            $table->string('jurisdiction', 2); // IT, CH
            $table->string('tax_code', 20)->nullable(); // Codice Fiscale
            $table->string('vat_number', 20)->nullable();
            $table->string('avs_number', 20)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('country_of_residence', 2)->nullable();
            $table->string('canton', 2)->nullable();
            $table->string('enasarco_mandate', 10)->nullable(); // mono, pluri
            $table->boolean('firr_enabled')->default(false);
            $table->boolean('is_cultural_sector')->default(false);
            $table->string('activity_type', 20)->nullable(); // artist, athlete, speaker
            $table->string('cu_causal', 3)->nullable(); // overrides the regime default
            $table->decimal('third_party_ytd_income', 14, 2)->default(0);
            $table->unsignedSmallInteger('third_party_income_year')->nullable();
            $table->date('third_party_declared_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $this->audit($table);

            $table->index(['tenant_id', 'regime'], 'fiscal_rp_regime_ix');
            $table->index(['tenant_id', 'jurisdiction'], 'fiscal_rp_jurisdiction_ix');
            $table->index(['tenant_id', 'tax_code'], 'fiscal_rp_tax_code_ix');
        });
    }

    private function certificates(): void
    {
        if (Schema::hasTable('fiscal_agent_reduced_withholding_certs')) {
            return;
        }

        Schema::create('fiscal_agent_reduced_withholding_certs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('recipient_profile_id')->constrained('fiscal_recipient_profiles')->cascadeOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->date('received_on');
            $table->date('valid_from');
            $table->date('valid_to');
            $table->string('pec_message_id', 200)->nullable();
            $table->string('pec_archive_reference', 200)->nullable();
            $table->string('document_path', 500)->nullable();
            $table->text('notes')->nullable();
            $this->audit($table);

            $table->index(['tenant_id', 'recipient_profile_id', 'fiscal_year'], 'fiscal_arwc_profile_year_ix');
            $table->index(['tenant_id', 'valid_to'], 'fiscal_arwc_valid_to_ix');
        });
    }

    private function inlNotifications(): void
    {
        if (Schema::hasTable('fiscal_occasional_inl_notifications')) {
            return;
        }

        Schema::create('fiscal_occasional_inl_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('recipient_profile_id')->constrained('fiscal_recipient_profiles')->cascadeOnDelete();
            $table->string('status', 20)->default('draft'); // draft, sent, cancelled
            $table->date('work_start_date');
            $table->date('work_end_date');
            $table->text('activity_description');
            $table->string('work_place', 200)->nullable();
            $table->decimal('estimated_compensation', 14, 2)->default(0);
            $table->dateTime('sent_at')->nullable();
            $table->string('protocol_reference', 120)->nullable();
            $table->json('payload')->nullable();
            $table->longText('xml')->nullable();
            $this->audit($table);

            $table->index(['tenant_id', 'recipient_profile_id', 'status'], 'fiscal_inl_profile_status_ix');
        });
    }

    private function settlements(): void
    {
        if (Schema::hasTable('fiscal_recipient_settlements')) {
            return;
        }

        Schema::create('fiscal_recipient_settlements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('recipient_profile_id')->constrained('fiscal_recipient_profiles')->cascadeOnDelete();
            $table->foreignUuid('inl_notification_id')->nullable()->constrained('fiscal_occasional_inl_notifications')->nullOnDelete();
            $table->string('regime', 40);
            $table->string('jurisdiction', 2);
            $table->string('currency', 3);
            $table->string('document_number', 60)->nullable();
            $table->date('service_date');
            $table->date('payment_date');
            $table->unsignedSmallInteger('tax_year');
            $table->string('accrual_month', 7); // YYYY-MM, the month of the payment (cash principle)
            $table->string('status', 20)->default('posted');
            $table->decimal('gross_amount', 14, 2);
            $table->decimal('flat_rate_deduction', 14, 2)->default(0);
            $table->decimal('taxable_base', 14, 2)->default(0);
            $table->decimal('withholding_rate', 8, 4)->default(0);
            $table->decimal('irpef_withholding', 14, 2)->default(0);
            $table->decimal('inps_base', 14, 2)->default(0);
            $table->decimal('inps_total', 14, 2)->default(0);
            $table->decimal('inps_worker', 14, 2)->default(0);
            $table->decimal('inps_company', 14, 2)->default(0);
            $table->decimal('stamp_duty', 14, 2)->default(0);
            $table->decimal('enasarco_base', 14, 2)->default(0);
            $table->decimal('enasarco_agent', 14, 2)->default(0);
            $table->decimal('enasarco_company', 14, 2)->default(0);
            $table->decimal('firr_accrual', 14, 2)->default(0);
            $table->decimal('avs_base', 14, 2)->default(0);
            $table->decimal('avs_employee', 14, 2)->default(0);
            $table->decimal('avs_employer', 14, 2)->default(0);
            $table->decimal('acquisition_expenses', 14, 2)->default(0);
            $table->decimal('source_tax', 14, 2)->default(0);
            $table->decimal('non_taxable_amount', 14, 2)->default(0);
            $table->string('non_taxable_code', 3)->nullable();
            $table->string('cu_causal', 3)->nullable();
            $table->decimal('net_payable', 14, 2);
            $table->decimal('company_cost', 14, 2);
            $table->date('f24_due_date')->nullable();
            $table->json('f24_lines')->nullable();
            $table->boolean('uniemens_required')->default(false);
            $table->string('payment_reference', 120)->nullable();
            $table->json('breakdown')->nullable();
            $table->json('warnings')->nullable();
            $table->text('notes')->nullable();
            $this->audit($table);

            $table->index(['tenant_id', 'recipient_profile_id', 'tax_year'], 'fiscal_rs_profile_year_ix');
            $table->index(['tenant_id', 'accrual_month'], 'fiscal_rs_month_ix');
            $table->index(['tenant_id', 'payment_date'], 'fiscal_rs_payment_ix');
        });
    }

    private function cuRecords(): void
    {
        if (Schema::hasTable('fiscal_cu_autonomous_records')) {
            return;
        }

        Schema::create('fiscal_cu_autonomous_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('recipient_profile_id')->constrained('fiscal_recipient_profiles')->cascadeOnDelete();
            $table->unsignedSmallInteger('tax_year');
            $table->string('return_type', 10); // CU (Italy), AVS_QST (Switzerland)
            $table->string('causal', 3)->nullable();
            $table->string('currency', 3);
            $table->decimal('gross_total', 14, 2)->default(0);
            $table->decimal('non_taxable_total', 14, 2)->default(0);
            $table->string('non_taxable_code', 3)->nullable();
            $table->decimal('taxable_total', 14, 2)->default(0);
            $table->decimal('withholding_total', 14, 2)->default(0);
            $table->decimal('inps_worker_total', 14, 2)->default(0);
            $table->decimal('inps_company_total', 14, 2)->default(0);
            $table->decimal('enasarco_total', 14, 2)->default(0);
            $table->decimal('avs_total', 14, 2)->default(0);
            $table->decimal('source_tax_total', 14, 2)->default(0);
            $table->unsignedInteger('settlement_count')->default(0);
            $table->string('status', 20)->default('generated');
            $table->dateTime('generated_at');
            $table->json('payload')->nullable();
            $this->audit($table);

            $table->unique(['tenant_id', 'recipient_profile_id', 'tax_year', 'return_type'], 'fiscal_cu_profile_year_uq');
            $table->index(['tenant_id', 'tax_year'], 'fiscal_cu_year_ix');
        });
    }
};
