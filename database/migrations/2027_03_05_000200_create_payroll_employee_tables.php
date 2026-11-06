<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the payroll knows of an employee beyond hr_employees. Identification numbers, IBAN, address and the names of
     * the children are encrypted by the models, so they are text columns.
     */
    public function up(): void
    {
        Schema::create('payroll_employee_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->text('social_security_number')->nullable();
            $table->text('iban')->nullable();
            $table->text('street')->nullable();
            $table->text('house_number')->nullable();
            $table->text('postal_code')->nullable();
            $table->text('city')->nullable();
            $table->string('gender', 1)->nullable();
            $table->string('residence_permit', 2)->nullable();
            $table->string('residence_country', 2)->default('CH');
            $table->string('residence_canton', 2)->nullable();
            $table->string('work_canton', 2)->nullable();
            $table->string('civil_status', 30)->nullable();
            $table->boolean('church_tax')->default(false);
            $table->string('pay_type', 10)->default('monthly');
            $table->unsignedSmallInteger('salary_installments_per_year')->default(12);
            $table->decimal('hourly_wage', 18, 4)->default(0);
            $table->decimal('weekly_hours', 6, 2)->default(42);
            $table->unsignedSmallInteger('holiday_weeks')->default(4);
            $table->boolean('is_fixed_term_contract')->default(false);
            $table->boolean('is_withholding_tax_subject')->default(false);
            $table->string('withholding_tax_code', 10)->nullable();
            $table->decimal('withholding_tax_rate_percent', 7, 4)->nullable();
            $table->decimal('non_occupational_accident_rate_percent', 7, 4)->default(0);
            $table->decimal('occupational_accident_rate_percent', 7, 4)->default(0);
            $table->decimal('daily_sickness_rate_percent', 7, 4)->default(0);
            $table->decimal('family_allowance_fund_rate_percent', 7, 4)->default(0);
            $table->decimal('pension_fund_employee_rate_percent', 7, 4)->nullable();
            $table->decimal('regional_surcharge_rate_percent', 7, 4)->default(0);
            $table->decimal('municipal_surcharge_rate_percent', 7, 4)->default(0);
            $table->string('collective_agreement_code', 30)->nullable();
            $table->string('contract_level', 30)->nullable();
            $table->string('inps_qualification', 30)->nullable();
            $table->string('region_code', 10)->nullable();
            $table->string('municipality_code', 10)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_id']);
        });

        Schema::create('payroll_employee_children', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->text('first_name');
            $table->text('last_name')->nullable();
            $table->date('date_of_birth');
            $table->boolean('in_education')->default(false);
            $table->date('education_end_date')->nullable();
            $table->boolean('allowance_eligible')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id']);
        });

        Schema::create('payroll_absences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('absence_type', 20);
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('percent', 6, 2)->default(100);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'start_date', 'end_date']);
            $table->index(['tenant_id', 'employee_id']);
        });

        Schema::create('payroll_inputs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedSmallInteger('month');
            $table->string('wage_type_code', 6);
            $table->decimal('quantity', 18, 4)->default(0);
            $table->decimal('rate', 18, 4)->default(0);
            $table->decimal('amount', 18, 2)->default(0);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'year', 'month']);
            $table->index(['tenant_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_inputs');
        Schema::dropIfExists('payroll_absences');
        Schema::dropIfExists('payroll_employee_children');
        Schema::dropIfExists('payroll_employee_profiles');
    }
};
