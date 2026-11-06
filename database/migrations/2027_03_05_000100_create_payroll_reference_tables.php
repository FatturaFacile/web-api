<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Payroll\Calculation\Italian\ItalianPayrollParameters;
use Modules\Payroll\Calculation\Swiss\SwissPayrollParameters;

return new class extends Migration
{
    /**
     * Legal tables and master data of the payroll. In the tables with a nullable tenant_id a row without tenant is a
     * generic one, shared by every company and read-only for them; a company can add its own rows, which win over the
     * generic ones. The generic rows inserted here are the values of the desktop application, nothing else.
     */
    public function up(): void
    {
        Schema::create('payroll_annual_parameters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('country', 2);
            $table->unsignedSmallInteger('year');
            $table->jsonb('parameters');
            $table->string('legal_reference')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'country', 'year']);
        });

        Schema::create('payroll_withholding_tax_tariffs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('canton', 2);
            $table->string('tariff_code', 10);
            $table->date('valid_from');
            $table->decimal('income_from', 18, 2);
            $table->decimal('income_step', 18, 2)->default(0);
            $table->decimal('minimum_tax', 18, 2)->default(0);
            $table->decimal('rate_percent', 7, 4);
            $table->timestamps();

            $table->index(['tenant_id', 'canton', 'tariff_code', 'valid_from'], 'payroll_tariffs_lookup_idx');
            $table->index(['canton', 'tariff_code', 'valid_from'], 'payroll_tariffs_generic_lookup_idx');
        });

        Schema::create('payroll_family_allowance_rates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('canton', 2);
            $table->date('valid_from');
            $table->decimal('child_allowance', 18, 2);
            $table->decimal('education_allowance', 18, 2);
            $table->timestamps();

            $table->index(['tenant_id', 'canton', 'valid_from']);
        });

        Schema::create('payroll_collective_agreements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->decimal('weekly_hours', 6, 2)->default(40);
            $table->unsignedSmallInteger('installments')->default(13);
            $table->unsignedSmallInteger('fourteenth_month')->default(7);
            $table->decimal('employer_inps_rate_percent', 7, 4)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('payroll_collective_agreement_levels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('agreement_id')->constrained('payroll_collective_agreements')->cascadeOnDelete();
            $table->string('level', 30);
            $table->string('description')->nullable();
            $table->decimal('monthly_minimum', 18, 2)->default(0);
            $table->timestamps();

            $table->unique(['agreement_id', 'level']);
            $table->index('tenant_id');
        });

        Schema::create('payroll_wage_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 6);
            $table->string('name');
            $table->string('kind', 20)->default('Earning');
            $table->boolean('subject_to_ahv')->default(false);
            $table->boolean('subject_to_alv')->default(false);
            $table->boolean('subject_to_uvg')->default(false);
            $table->boolean('subject_to_ktg')->default(false);
            $table->boolean('subject_to_bvg')->default(false);
            $table->boolean('subject_to_withholding_tax')->default(false);
            $table->boolean('is_irregular')->default(false);
            $table->string('certificate_box', 10)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('payroll_swiss_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->decimal('ahv_employee_rate_percent', 7, 4)->default(5.3);
            $table->decimal('ahv_employer_rate_percent', 7, 4)->default(5.3);
            $table->decimal('ahv_administration_cost_percent', 7, 4)->default(0);
            $table->decimal('alv_employee_rate_percent', 7, 4)->default(1.1);
            $table->decimal('alv_employer_rate_percent', 7, 4)->default(1.1);
            $table->decimal('alv_annual_ceiling', 18, 2)->default(148200);
            $table->decimal('uvg_annual_ceiling', 18, 2)->default(148200);
            $table->decimal('nbu_employee_rate_percent', 7, 4)->default(0);
            $table->decimal('bu_employer_rate_percent', 7, 4)->default(0);
            $table->decimal('ktg_employee_rate_percent', 7, 4)->default(0);
            $table->decimal('ktg_employer_rate_percent', 7, 4)->default(0);
            $table->decimal('fak_employer_rate_percent', 7, 4)->default(0);
            $table->decimal('bvg_employer_share_percent', 7, 4)->default(50);
            $table->decimal('bvg_risk_rate_percent', 7, 4)->default(0);
            $table->decimal('pensioner_allowance_monthly', 18, 2)->default(1400);
            $table->string('default_work_canton', 2)->default('TI');
            $table->string('ahv_member_number', 50)->nullable();
            $table->string('uvg_insurer')->nullable();
            $table->string('bvg_insurer')->nullable();
            $table->timestamps();
        });

        Schema::create('payroll_italian_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->string('inps_company_number', 30)->nullable();
            $table->string('inps_office_code', 30)->nullable();
            $table->string('inail_code', 30)->nullable();
            $table->decimal('employee_inps_rate_percent', 7, 4)->default(9.19);
            $table->decimal('employer_inps_rate_percent', 7, 4)->default(29);
            $table->timestamps();
        });

        $this->insertGenericRows();
    }

    public function down(): void
    {
        foreach ([
            'payroll_italian_settings', 'payroll_swiss_settings', 'payroll_wage_types', 'payroll_collective_agreement_levels',
            'payroll_collective_agreements', 'payroll_family_allowance_rates', 'payroll_withholding_tax_tariffs', 'payroll_annual_parameters',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * The parameters of the desktop (2026) and the federal minimum family allowances from 2025, which the desktop
     * creates as editable starting values.
     */
    private function insertGenericRows(): void
    {
        $now = now();

        DB::table('payroll_annual_parameters')->insert([
            [
                'id' => (string) Str::uuid7(),
                'tenant_id' => null,
                'country' => 'CH',
                'year' => 2026,
                'parameters' => json_encode((new SwissPayrollParameters)->toArray(), JSON_THROW_ON_ERROR),
                'legal_reference' => 'SwissPayrollRules, SwissSocialInsuranceParameters (desktop)',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => (string) Str::uuid7(),
                'tenant_id' => null,
                'country' => 'IT',
                'year' => 2026,
                'parameters' => json_encode((new ItalianPayrollParameters)->toArray(), JSON_THROW_ON_ERROR),
                'legal_reference' => 'ItalianPayrollParameters (desktop): Legge di Bilancio 2026, art. 13 TUIR, L. 207/2024, circolare INPS n. 6/2026',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        foreach (['AG', 'AI', 'AR', 'BE', 'BL', 'BS', 'FR', 'GE', 'GL', 'GR', 'JU', 'LU', 'NE', 'NW', 'OW', 'SG', 'SH', 'SO', 'SZ', 'TG', 'TI', 'UR', 'VD', 'VS', 'ZG', 'ZH'] as $canton) {
            DB::table('payroll_family_allowance_rates')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => null,
                'canton' => $canton,
                'valid_from' => '2025-01-01',
                'child_allowance' => '215.00',
                'education_allowance' => '268.00',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
