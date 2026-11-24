<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mod 074 — Leave, Permits & Absences Management (Ferie, ROL, Ex-Festività, INPS PUC, SUVA/INAIL,
 * Art. 329a/329b CO Ferienkürzung, Comporto, and Department Absence Gantt with Minimum Staffing Thresholds).
 * Dialect-agnostic: runs identically on SQLite and PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lm_holiday_calendars')) {
            Schema::create('lm_holiday_calendars', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('code', 60);
                $table->string('name', 160);
                $table->string('country', 2)->default('CH'); // CH | IT
                $table->string('canton_or_province_code', 20)->nullable();
                $table->string('municipality_name', 120)->nullable();
                $table->string('municipality_patron_saint_date', 5)->nullable(); // MM-DD e.g. 12-07
                $table->string('municipality_patron_saint_name', 120)->nullable();
                $table->unsignedSmallInteger('year');
                $table->string('sunday_holiday_rule', 40)->default('compensatory_pay_or_day');
                $table->json('holidays')->nullable();
                $table->json('working_days')->nullable();
                $table->boolean('is_active')->default(true);
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->uuid('deleted_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code', 'year'], 'lm_hol_cal_code_yr_uq');
                $table->index(['tenant_id', 'country', 'canton_or_province_code', 'year'], 'lm_hol_cal_lookup_idx');
            });
        }

        if (! Schema::hasTable('lm_leave_entitlement_policies')) {
            Schema::create('lm_leave_entitlement_policies', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('code', 60);
                $table->string('name', 160);
                $table->string('country', 2)->default('CH'); // CH | IT
                $table->string('leave_type', 60);
                $table->string('ccnl_or_contract_ref', 120)->nullable();
                $table->string('unit_mode', 10)->default('days'); // days | hours
                $table->decimal('annual_entitlement', 8, 2)->default(20.00);
                $table->decimal('annual_entitlement_under_20', 8, 2)->nullable();
                $table->decimal('annual_entitlement_over_50', 8, 2)->nullable();
                $table->decimal('hours_per_workday', 5, 2)->default(8.00);
                $table->string('accrual_frequency', 20)->default('monthly'); // monthly | yearly | none
                $table->boolean('prorate_by_fte')->default(true);
                $table->boolean('prorate_hire_termination')->default(true);
                $table->unsignedTinyInteger('min_days_worked_in_month_for_accrual')->default(15);
                $table->decimal('max_carry_over', 8, 2)->nullable();
                $table->unsignedSmallInteger('expiration_months')->nullable();
                $table->boolean('inps_18_month_alert_enabled')->default(false);
                $table->boolean('requires_approval')->default(true);
                $table->boolean('requires_medical_certificate')->default(false);
                $table->boolean('requires_inps_puc')->default(false);
                $table->unsignedSmallInteger('waiting_period_days')->default(0);
                $table->decimal('employer_indemnity_percent', 5, 2)->default(100.00);
                $table->decimal('insurer_indemnity_percent', 5, 2)->default(0.00);
                $table->decimal('insurer_indemnity_percent_tier2', 5, 2)->nullable();
                $table->unsignedSmallInteger('comporto_max_days')->nullable();
                $table->unsignedSmallInteger('comporto_window_months')->default(12);
                $table->unsignedTinyInteger('ch_curtailment_grace_months')->default(1);
                $table->boolean('ch_curtailment_enabled')->default(false);
                $table->decimal('legge_104_monthly_days_limit', 5, 2)->nullable();
                $table->boolean('is_paid')->default(true);
                $table->boolean('is_active')->default(true);
                $table->json('metadata')->nullable();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->uuid('deleted_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code'], 'lm_policy_code_uq');
                $table->index(['tenant_id', 'country', 'leave_type', 'is_active'], 'lm_policy_lookup_idx');
            });
        }

        if (! Schema::hasTable('lm_employee_leave_accrual_ledgers')) {
            Schema::create('lm_employee_leave_accrual_ledgers', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->foreignUuid('policy_id')->nullable()->constrained('lm_leave_entitlement_policies')->nullOnDelete();
                $table->unsignedSmallInteger('fiscal_year');
                $table->string('country', 2)->default('CH');
                $table->string('leave_type', 60);
                $table->string('unit_mode', 10)->default('days');
                $table->decimal('prior_year_carry_over', 8, 2)->default(0.00);
                $table->decimal('accrued_ytd', 8, 2)->default(0.00);
                $table->decimal('curtailment_reduction_ytd', 8, 2)->default(0.00);
                $table->decimal('taken_ytd', 8, 2)->default(0.00);
                $table->decimal('planned_approved_future', 8, 2)->default(0.00);
                $table->decimal('pending_approval_amount', 8, 2)->default(0.00);
                $table->decimal('projected_year_end_balance', 8, 2)->default(0.00);
                $table->decimal('annual_entitlement_target', 8, 2)->default(0.00);
                $table->date('expiration_deadline_date')->nullable();
                $table->decimal('expiring_carry_over_amount', 8, 2)->default(0.00);
                $table->string('deadline_alert_level', 20)->default('none'); // none | warning | critical | expired
                $table->string('last_accrued_period', 7)->nullable(); // YYYY-MM
                $table->json('accrual_journal')->nullable();
                $table->json('metadata')->nullable();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->uuid('deleted_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'employee_id', 'fiscal_year', 'leave_type'], 'lm_ledger_emp_yr_type_uq');
                $table->index(['tenant_id', 'fiscal_year', 'leave_type'], 'lm_ledger_lookup_idx');
            });
        }

        if (! Schema::hasTable('lm_department_staffing_thresholds')) {
            Schema::create('lm_department_staffing_thresholds', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('department_id')->constrained('hr_departments')->cascadeOnDelete();
                $table->unsignedSmallInteger('min_present_count')->default(1);
                $table->decimal('min_present_percent', 5, 2)->default(50.00);
                $table->boolean('enforce_hard_block')->default(false);
                $table->date('effective_from')->nullable();
                $table->date('effective_to')->nullable();
                $table->text('notes')->nullable();
                $table->boolean('is_active')->default(true);
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->uuid('deleted_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'department_id'], 'lm_staffing_dept_uq');
                $table->index(['tenant_id', 'is_active'], 'lm_staffing_active_idx');
            });
        }

        if (! Schema::hasTable('lm_leave_requests')) {
            Schema::create('lm_leave_requests', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->foreignUuid('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
                $table->foreignUuid('policy_id')->nullable()->constrained('lm_leave_entitlement_policies')->nullOnDelete();
                $table->foreignUuid('ledger_id')->nullable()->constrained('lm_employee_leave_accrual_ledgers')->nullOnDelete();
                $table->uuid('hr_leave_request_id')->nullable();
                $table->string('request_number', 60);
                $table->string('country', 2)->default('CH');
                $table->string('leave_type', 60);
                $table->date('start_date');
                $table->date('end_date');
                $table->string('half_day_or_hourly_duration', 40)->nullable(); // full_day | morning | afternoon | hourly:4.00
                $table->string('duration_unit', 10)->default('days'); // days | hours
                $table->decimal('requested_days', 6, 2)->default(0.00);
                $table->decimal('requested_hours', 8, 2)->default(0.00);
                $table->string('status', 30)->default('draft'); // draft | pending_approval | approved | rejected | cancelled
                $table->string('inps_medical_certificate_protocol_number', 9)->nullable();
                $table->string('suva_claim_number', 60)->nullable();
                $table->string('inail_claim_number', 60)->nullable();
                $table->uuid('medical_certificate_doc_id')->nullable();
                $table->date('certificate_issue_date')->nullable();
                $table->boolean('is_accident_occupational')->nullable();
                $table->boolean('is_fault_absence')->default(false);
                $table->boolean('is_pregnancy_related')->default(false);
                $table->unsignedSmallInteger('ijm_ktg_waiting_days')->nullable();
                $table->string('ipg_eo_allowance_code', 40)->nullable();
                $table->string('legge_104_beneficiary_ref', 80)->nullable();
                $table->json('indemnity_breakdown')->nullable();
                $table->json('staffing_check_result')->nullable();
                $table->uuid('workflow_instance_id')->nullable();
                $table->boolean('is_locked')->default(false);
                $table->uuid('approved_by_user_id')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->text('notes')->nullable();
                $table->json('metadata')->nullable();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->uuid('deleted_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'request_number'], 'lm_req_number_uq');
                $table->index(['tenant_id', 'employee_id', 'start_date', 'end_date'], 'lm_req_emp_dates_idx');
                $table->index(['tenant_id', 'status', 'leave_type'], 'lm_req_status_type_idx');
                $table->index(['tenant_id', 'inps_medical_certificate_protocol_number'], 'lm_req_puc_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lm_leave_requests');
        Schema::dropIfExists('lm_department_staffing_thresholds');
        Schema::dropIfExists('lm_employee_leave_accrual_ledgers');
        Schema::dropIfExists('lm_leave_entitlement_policies');
        Schema::dropIfExists('lm_holiday_calendars');
    }
};
