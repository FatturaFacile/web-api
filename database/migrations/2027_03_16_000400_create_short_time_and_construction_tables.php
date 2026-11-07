<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Short-time work and bad-weather cases with their monthly claims, and the construction parameters of a collective
     * agreement. Nothing is seeded for a CCL: its figures are entered with their source.
     */
    public function up(): void
    {
        Schema::create('payroll_short_time_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->foreignUuid('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
            $table->text('cause');
            $table->date('notified_on')->nullable();
            $table->boolean('is_urgent')->default(false);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('authority_reference', 120)->nullable();
            $table->string('status', 20)->default('notified');
            $table->timestamps();

            $table->index(['tenant_id', 'kind', 'starts_on']);
        });

        Schema::create('payroll_short_time_claims', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('short_time_case_id')->constrained('payroll_short_time_cases')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedSmallInteger('month');
            $table->string('status', 20)->default('draft');
            $table->unsignedSmallInteger('parameters_year');
            $table->decimal('target_hours', 12, 2)->default(0);
            $table->decimal('lost_hours', 12, 2)->default(0);
            $table->decimal('loss_percent', 7, 2)->default(0);
            $table->boolean('meets_minimum_loss')->default(true);
            $table->decimal('total_compensation', 18, 2)->default(0);
            $table->decimal('total_employer_refund', 18, 2)->default(0);
            $table->date('claim_due_on');
            $table->jsonb('warnings')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('reimbursed_at')->nullable();
            $table->decimal('reimbursed_amount', 18, 2)->nullable();
            $table->timestamps();

            $table->unique(['short_time_case_id', 'year', 'month']);
        });

        Schema::create('payroll_short_time_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('short_time_claim_id')->constrained('payroll_short_time_claims')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->boolean('is_eligible')->default(true);
            $table->string('ineligibility_reason')->nullable();
            $table->decimal('target_hours', 10, 2)->default(0);
            $table->decimal('worked_hours', 10, 2)->default(0);
            $table->decimal('absence_hours', 10, 2)->default(0);
            $table->decimal('overtime_deducted_hours', 10, 2)->default(0);
            $table->decimal('lost_hours', 10, 2)->default(0);
            $table->decimal('waiting_hours', 10, 2)->default(0);
            $table->decimal('compensable_hours', 10, 2)->default(0);
            $table->decimal('insured_monthly_salary', 18, 2)->default(0);
            $table->decimal('lost_earnings', 18, 2)->default(0);
            $table->decimal('compensation', 18, 2)->default(0);
            $table->decimal('wage_reduction', 18, 2)->default(0);
            $table->decimal('employer_refund', 18, 2)->default(0);
            $table->timestamps();

            $table->unique(['short_time_claim_id', 'employee_id']);
        });

        Schema::create('payroll_ccl_construction_parameters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('agreement_code', 30);
            $table->date('valid_from');
            $table->decimal('annual_hours', 8, 2);
            $table->unsignedSmallInteger('accounting_year_start_month');
            $table->jsonb('minimum_wages');
            $table->text('source');
            $table->timestamps();

            $table->unique(['tenant_id', 'agreement_code', 'valid_from'], 'payroll_ccl_construction_version');
        });

        Schema::create('payroll_ccl_work_calendars', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('agreement_code', 30);
            $table->unsignedSmallInteger('year');
            $table->unsignedSmallInteger('month');
            $table->decimal('planned_hours', 8, 2);
            $table->timestamps();

            $table->unique(['tenant_id', 'agreement_code', 'year', 'month'], 'payroll_ccl_work_calendar_month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_ccl_work_calendars');
        Schema::dropIfExists('payroll_ccl_construction_parameters');
        Schema::dropIfExists('payroll_short_time_lines');
        Schema::dropIfExists('payroll_short_time_claims');
        Schema::dropIfExists('payroll_short_time_cases');
    }
};
