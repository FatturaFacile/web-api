<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Italian payroll pre-processing and labour consultant export (Mod 077): the monthly period with its closing state, the
 * attendance (LUL) lines handed to the consultant, the consultant code mapping, the accounting summary that comes back,
 * the payslip distribution with its download audit (L. 4/1953) and the GL account settings used to post the summary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ipp_payroll_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('legal_entity_id')->nullable();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->boolean('include_tredicesima')->default(false);
            $table->boolean('include_quattordicesima')->default(false);
            $table->string('status', 24)->default('attendance_open');
            $table->string('export_format', 24)->nullable();
            $table->string('export_checksum', 64)->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->uuid('validated_by')->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->uuid('exported_by')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->jsonb('validation_report_json')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'legal_entity_id', 'year', 'month'], 'ipp_periods_unique');
        });

        Schema::create('ipp_lul_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('payroll_period_id')->constrained('ipp_payroll_periods')->cascadeOnDelete();
            $table->uuid('employee_id');
            $table->string('matricola_inps', 32)->nullable();
            $table->string('causal_code', 12);
            $table->decimal('hours_or_days', 10, 2)->default(0);
            $table->unsignedInteger('meal_vouchers_count')->default(0);
            $table->decimal('reimbursement_amount', 14, 2)->default(0);
            $table->decimal('fringe_benefit_amount', 14, 2)->default(0);
            $table->string('inps_protocol_number', 40)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'payroll_period_id', 'employee_id'], 'ipp_lul_period_employee_idx');
        });

        Schema::create('ipp_causal_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('consultant_format', 24);
            $table->string('causal_code', 12);
            $table->string('external_code', 24);
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'consultant_format', 'causal_code'], 'ipp_causal_unique');
        });

        Schema::create('ipp_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('disk', 32);
            $table->string('path', 255);
            $table->string('sha256', 64);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
        });

        Schema::create('ipp_payslip_distributions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('payroll_period_id')->constrained('ipp_payroll_periods')->cascadeOnDelete();
            $table->uuid('employee_id');
            $table->uuid('cedolino_pdf_document_id')->nullable();
            $table->uuid('cu_pdf_document_id')->nullable();
            $table->decimal('net_amount', 14, 2)->default(0);
            $table->timestamp('viewed_by_employee_at')->nullable();
            $table->string('viewed_from_ip', 45)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'payroll_period_id', 'employee_id'], 'ipp_distribution_unique');
        });

        Schema::create('ipp_download_audits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('distribution_id')->constrained('ipp_payslip_distributions')->cascadeOnDelete();
            $table->uuid('employee_id');
            $table->string('document_kind', 16);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->boolean('biometric_confirmed')->default(false);
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'distribution_id']);
        });

        Schema::create('ipp_accounting_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('payroll_period_id')->constrained('ipp_payroll_periods')->cascadeOnDelete();
            $table->decimal('total_gross_wages', 16, 2);
            $table->decimal('inps_employee_withholding', 16, 2);
            $table->decimal('inps_employer_contribution', 16, 2);
            $table->decimal('irpef_withholding', 16, 2);
            $table->decimal('regional_municipal_addizionali', 16, 2);
            $table->decimal('tfr_monthly_accrual', 16, 2);
            $table->decimal('tfr_fondo_tesoreria_or_previdenza', 16, 2);
            $table->decimal('inail_accrual', 16, 2)->default(0);
            $table->decimal('net_payable', 16, 2);
            $table->uuid('gl_journal_entry_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'payroll_period_id'], 'ipp_accounting_period_unique');
        });

        Schema::create('ipp_gl_account_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('role', 32);
            $table->uuid('account_id');
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'role'], 'ipp_gl_role_unique');
        });
    }

    public function down(): void
    {
        foreach (['ipp_gl_account_settings', 'ipp_accounting_imports', 'ipp_download_audits', 'ipp_payslip_distributions', 'ipp_documents', 'ipp_causal_mappings', 'ipp_lul_records', 'ipp_payroll_periods'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
