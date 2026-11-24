<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Monthly runs, payslips and salary certificates. Payslips and certificates carry no foreign key to the employee
     * on purpose: they must outlive the employee for the legal retention period. The history of a run has no foreign
     * keys at all, so nothing can cascade into it.
     */
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedSmallInteger('month');
            $table->string('country', 2);
            $table->string('currency', 3);
            $table->string('status', 10)->default('Draft');
            $table->date('payment_date')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->uuid('finalized_by')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->uuid('reopened_by')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->unsignedInteger('reopen_count')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'year', 'month']);
        });

        Schema::create('payroll_run_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('payroll_run_id');
            $table->string('action', 20);
            $table->text('reason')->nullable();
            $table->jsonb('totals')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->string('actor_name');
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'payroll_run_id']);
        });

        Schema::create('payroll_payslips', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->uuid('employee_id');
            $table->unsignedSmallInteger('year');
            $table->unsignedSmallInteger('month');
            $table->string('employee_name');
            $table->decimal('paid_days', 8, 4);
            $table->decimal('gross_salary', 18, 2);
            $table->decimal('ahv_basis', 18, 2)->default(0);
            $table->decimal('alv_basis', 18, 2)->default(0);
            $table->decimal('uvg_basis', 18, 2)->default(0);
            $table->decimal('ktg_basis', 18, 2)->default(0);
            $table->decimal('bvg_insured_salary', 18, 2)->default(0);
            $table->decimal('withholding_tax_basis', 18, 2)->default(0);
            $table->decimal('withholding_tax_rate_percent', 7, 4)->default(0);
            $table->string('withholding_tax_code', 10)->nullable();
            $table->string('withholding_tax_canton', 2)->nullable();
            $table->decimal('employee_deductions', 18, 2)->default(0);
            $table->decimal('net_salary', 18, 2)->default(0);
            $table->decimal('payout_amount', 18, 2)->default(0);
            $table->decimal('employer_contributions', 18, 2)->default(0);
            $table->text('warnings')->nullable();
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id']);
            $table->index(['tenant_id', 'year', 'month']);
            $table->index(['tenant_id', 'employee_id']);
        });

        Schema::create('payroll_payslip_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('payslip_id')->constrained('payroll_payslips')->cascadeOnDelete();
            $table->string('wage_type_code', 10);
            $table->string('description');
            $table->string('kind', 20);
            $table->decimal('quantity', 18, 4)->default(0);
            $table->decimal('rate', 18, 4)->default(0);
            $table->decimal('amount', 18, 2);
            $table->string('certificate_box', 10)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'payslip_id']);
        });

        Schema::create('payroll_salary_certificates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('employee_id');
            $table->unsignedSmallInteger('year');
            $table->date('period_from');
            $table->date('period_to');
            $table->decimal('box1_salary', 18, 2)->default(0);
            $table->decimal('box2_benefits', 18, 2)->default(0);
            $table->decimal('box3_irregular', 18, 2)->default(0);
            $table->decimal('box4_capital', 18, 2)->default(0);
            $table->decimal('box7_other', 18, 2)->default(0);
            $table->decimal('box8_gross', 18, 2)->default(0);
            $table->decimal('box9_social_contributions', 18, 2)->default(0);
            $table->decimal('box10_pension', 18, 2)->default(0);
            $table->decimal('box11_net', 18, 2)->default(0);
            $table->decimal('box12_withholding_tax', 18, 2)->default(0);
            $table->decimal('box13_expenses', 18, 2)->default(0);
            $table->text('remarks')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'year', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_salary_certificates');
        Schema::dropIfExists('payroll_payslip_lines');
        Schema::dropIfExists('payroll_payslips');
        Schema::dropIfExists('payroll_run_events');
        Schema::dropIfExists('payroll_runs');
    }
};
