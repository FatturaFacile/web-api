<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Swiss payroll operational data: employee payroll profiles, payroll runs, payslips with their lines and
 * ELM-style salary declarations. All money columns are integer Rappen (1 CHF = 100 Rappen).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Swiss payroll profile per employee (AHV number, cantons, permit, withholding tax data)
        if (! Schema::hasTable('hr_ch_payroll_profiles')) {
            Schema::create('hr_ch_payroll_profiles', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->text('ahv_number')->nullable(); // encrypted at rest (Eloquent `encrypted` cast)
                $table->char('work_canton', 2);
                $table->char('residence_canton', 2)->nullable();
                $table->char('residence_country', 2)->default('CH');
                $table->string('residence_municipality_bfs', 10)->nullable();
                $table->string('permit_type', 10)->nullable(); // B, C, L, G, F, N, S, CI
                $table->string('civil_status', 30)->default('single');
                $table->unsignedSmallInteger('children_count')->default(0);
                $table->boolean('church_tax')->default(false);
                $table->string('qst_tariff_code', 10)->nullable();
                $table->char('qst_canton', 2)->nullable();
                $table->boolean('bvg_insured')->default(true);
                $table->string('bvg_plan_code', 40)->nullable();
                $table->string('uvg_class', 20)->nullable();
                $table->boolean('ktg_insured')->default(false);
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'employee_id']);
            });
        }

        // 2. Payroll runs (one period, lifecycle draft -> calculated -> approved -> closed)
        if (! Schema::hasTable('hr_ch_payroll_runs')) {
            Schema::create('hr_ch_payroll_runs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->string('run_number', 60);
                $table->unsignedSmallInteger('period_year');
                $table->unsignedTinyInteger('period_month');
                $table->date('period_start');
                $table->date('period_end');
                $table->date('payment_date')->nullable();
                $table->string('status', 20)->default('draft')->index();
                $table->uuid('rate_set_id')->nullable()->index();
                $table->string('currency', 3)->default('CHF');
                $table->jsonb('employee_ids')->nullable();
                $table->jsonb('inputs')->nullable();
                $table->unsignedInteger('employee_count')->default(0);
                $table->bigInteger('gross_rappen')->default(0);
                $table->bigInteger('employee_deductions_rappen')->default(0);
                $table->bigInteger('employer_contributions_rappen')->default(0);
                $table->bigInteger('withholding_tax_rappen')->default(0);
                $table->bigInteger('net_rappen')->default(0);
                $table->timestamp('calculated_at')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->uuid('approved_by_user_id')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->uuid('closed_by_user_id')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'run_number']);
                $table->index(['tenant_id', 'period_year', 'period_month']);
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE hr_ch_payroll_runs ADD CONSTRAINT chk_hr_ch_payroll_runs_status CHECK (status IN ('draft', 'calculated', 'approved', 'closed'))");
                DB::statement('ALTER TABLE hr_ch_payroll_runs ADD CONSTRAINT chk_hr_ch_payroll_runs_period CHECK (period_month BETWEEN 1 AND 12 AND period_end >= period_start)');
            }
        }

        // 3. Payslips (one per employee and run)
        if (! Schema::hasTable('hr_ch_payslips')) {
            Schema::create('hr_ch_payslips', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('payroll_run_id')->constrained('hr_ch_payroll_runs')->cascadeOnDelete();
                $table->foreignUuid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->uuid('payroll_profile_id')->nullable()->index();
                $table->uuid('rate_set_id')->index();
                $table->date('period_start');
                $table->date('period_end');
                $table->unsignedSmallInteger('days_worked');
                $table->decimal('fte_percentage', 6, 2)->default(100.00);
                $table->bigInteger('gross_rappen')->default(0);
                $table->bigInteger('ahv_base_rappen')->default(0);
                $table->bigInteger('alv_base_rappen')->default(0);
                $table->bigInteger('alv_solidarity_base_rappen')->default(0);
                $table->bigInteger('bvg_insured_salary_rappen')->default(0);
                $table->bigInteger('uvg_base_rappen')->default(0);
                $table->bigInteger('qst_base_rappen')->default(0);
                $table->string('qst_tariff_code', 10)->nullable();
                $table->char('qst_canton', 2)->nullable();
                $table->bigInteger('withholding_tax_rappen')->default(0);
                $table->bigInteger('employee_deductions_rappen')->default(0);
                $table->bigInteger('employer_contributions_rappen')->default(0);
                $table->bigInteger('net_rappen')->default(0);
                $table->jsonb('snapshot')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['payroll_run_id', 'employee_id']);
            });
        }

        // 4. Payslip lines (earnings, social insurance contributions and withholding tax; employee or employer side)
        if (! Schema::hasTable('hr_ch_payslip_lines')) {
            Schema::create('hr_ch_payslip_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('payslip_id')->constrained('hr_ch_payslips')->cascadeOnDelete();
                $table->uuid('wage_type_id')->nullable()->index();
                $table->string('code', 40);
                $table->string('label', 150);
                $table->string('line_type', 30); // earning, contribution, withholding_tax
                $table->string('side', 10); // employee, employer
                $table->bigInteger('base_rappen')->default(0);
                $table->unsignedInteger('rate_ppm')->nullable();
                $table->bigInteger('amount_rappen');
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['payslip_id', 'sort_order']);
            });
        }

        // 5. ELM-style salary declarations (XML stored on disk, SHA-256 hash kept for integrity)
        if (! Schema::hasTable('hr_ch_salary_declarations')) {
            Schema::create('hr_ch_salary_declarations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->string('declaration_number', 60);
                $table->unsignedSmallInteger('period_year')->index();
                $table->string('status', 20)->default('generated');
                $table->jsonb('payroll_run_ids');
                $table->unsignedInteger('employee_count')->default(0);
                $table->bigInteger('gross_rappen')->default(0);
                $table->bigInteger('ahv_base_rappen')->default(0);
                $table->bigInteger('withholding_tax_rappen')->default(0);
                $table->bigInteger('net_rappen')->default(0);
                $table->string('xml_disk', 40);
                $table->string('xml_path', 500);
                $table->char('xml_sha256', 64);
                $table->string('schema_version', 20);
                $table->boolean('xsd_validated')->default(false);
                $table->timestamp('generated_at');
                $table->uuid('generated_by_user_id')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'declaration_number']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_ch_salary_declarations');
        Schema::dropIfExists('hr_ch_payslip_lines');
        Schema::dropIfExists('hr_ch_payslips');
        Schema::dropIfExists('hr_ch_payroll_runs');
        Schema::dropIfExists('hr_ch_payroll_profiles');
    }
};
