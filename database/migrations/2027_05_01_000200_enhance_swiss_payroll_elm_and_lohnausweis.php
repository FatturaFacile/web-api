<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enhances Swiss payroll profiles (AHV 21 retiree waiver, FTA Circular No. 45 QST extrapolation,
     * CH-IT new frontier worker 80% rule, and Swiss work permits) and salary certificates (Form 11
     * checkboxes F/G, boxes 10.1, 10.2, 13.1, 13.2, 15, and eCH-0019 PDF417 barcode).
     */
    public function up(): void
    {
        Schema::table('payroll_employee_profiles', function (Blueprint $table): void {
            $this->addColumn($table, 'payroll_employee_profiles', 'waive_retiree_allowance', fn () => $table->boolean('waive_retiree_allowance')->default(false));
            $this->addColumn($table, 'payroll_employee_profiles', 'extrapolate_qst_to_full_time', fn () => $table->boolean('extrapolate_qst_to_full_time')->default(false));
            $this->addColumn($table, 'payroll_employee_profiles', 'other_employment_fte_percent', fn () => $table->decimal('other_employment_fte_percent', 6, 2)->default(0));
            $this->addColumn($table, 'payroll_employee_profiles', 'qst_rate_determining_percent', fn () => $table->decimal('qst_rate_determining_percent', 6, 2)->nullable());
            $this->addColumn($table, 'payroll_employee_profiles', 'frontier_apply_80_percent_basis', fn () => $table->boolean('frontier_apply_80_percent_basis')->default(false));
            $this->addColumn($table, 'payroll_employee_profiles', 'swiss_permit_type', fn () => $table->string('swiss_permit_type', 20)->nullable());
            $this->addColumn($table, 'payroll_employee_profiles', 'swiss_permit_number', fn () => $table->text('swiss_permit_number')->nullable());
            $this->addColumn($table, 'payroll_employee_profiles', 'swiss_permit_valid_until', fn () => $table->date('swiss_permit_valid_until')->nullable());
            $this->addColumn($table, 'payroll_employee_profiles', 'spouse_work_status', fn () => $table->string('spouse_work_status', 30)->nullable());
            $this->addColumn($table, 'payroll_employee_profiles', 'religion_code', fn () => $table->string('religion_code', 30)->nullable());
            $this->addColumn($table, 'payroll_employee_profiles', 'single_parent_concubinage', fn () => $table->boolean('single_parent_concubinage')->default(false));
        });

        Schema::table('payroll_salary_certificates', function (Blueprint $table): void {
            $this->addColumn($table, 'payroll_salary_certificates', 'checkbox_f_transport', fn () => $table->boolean('checkbox_f_transport')->default(false));
            $this->addColumn($table, 'payroll_salary_certificates', 'checkbox_g_canteen', fn () => $table->boolean('checkbox_g_canteen')->default(false));
            $this->addColumn($table, 'payroll_salary_certificates', 'box10_1_ordinary_pension', fn () => $table->decimal('box10_1_ordinary_pension', 14, 2)->default(0));
            $this->addColumn($table, 'payroll_salary_certificates', 'box10_2_pension_buyin', fn () => $table->decimal('box10_2_pension_buyin', 14, 2)->default(0));
            $this->addColumn($table, 'payroll_salary_certificates', 'box13_1_effective_expenses', fn () => $table->decimal('box13_1_effective_expenses', 14, 2)->default(0));
            $this->addColumn($table, 'payroll_salary_certificates', 'box13_2_lump_sum_expenses', fn () => $table->decimal('box13_2_lump_sum_expenses', 14, 2)->default(0));
            $this->addColumn($table, 'payroll_salary_certificates', 'box15_remarks', fn () => $table->text('box15_remarks')->nullable());
            $this->addColumn($table, 'payroll_salary_certificates', 'barcode_payload', fn () => $table->text('barcode_payload')->nullable());
            $this->addColumn($table, 'payroll_salary_certificates', 'barcode_svg', fn () => $table->text('barcode_svg')->nullable());
        });
    }

    public function down(): void
    {
        // Additive enterprise migration policy.
    }

    private function addColumn(Blueprint $table, string $tableName, string $column, Closure $definition): void
    {
        if (! Schema::hasColumn($tableName, $column)) {
            $definition();
        }
    }
};
