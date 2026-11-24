<?php

declare(strict_types=1);

use Database\Seeders\PayrollReferenceSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-year legal parameters by topic, each with its source, withholding tax statements and the frontier worker data of
     * the payroll profile. Only values read in an official source are seeded here; a value that could not be verified
     * stays null and the calculation that needs it refuses to run until the company or an operator sets it.
     */
    public function up(): void
    {
        Schema::create('payroll_legal_parameters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('topic', 40);
            $table->unsignedSmallInteger('year');
            $table->jsonb('parameters');
            $table->text('source');
            $table->timestamps();

            $table->index(['topic', 'year']);
        });

        DB::statement('CREATE UNIQUE INDEX payroll_legal_parameters_generic ON payroll_legal_parameters (topic, year) WHERE tenant_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX payroll_legal_parameters_company ON payroll_legal_parameters (tenant_id, topic, year) WHERE tenant_id IS NOT NULL');

        Schema::table('payroll_employee_profiles', function (Blueprint $table): void {
            $table->text('birth_place')->nullable();
            $table->text('italian_tax_code')->nullable();
            $table->string('frontier_regime', 20)->nullable();
            $table->date('frontier_status_valid_from')->nullable();
            $table->boolean('frontier_commune_in_zone')->nullable();
            $table->boolean('frontier_returns_daily')->default(true);
            $table->string('ccl_zone', 30)->nullable();
        });

        Schema::table('payroll_swiss_settings', function (Blueprint $table): void {
            $table->jsonb('withholding_debtor_numbers')->nullable();
        });

        Schema::create('payroll_frontier_years', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedSmallInteger('nights_away');
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_id', 'year']);
        });

        Schema::create('payroll_withholding_statements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('canton', 2);
            $table->unsignedSmallInteger('year');
            $table->string('period_type', 10);
            $table->unsignedSmallInteger('period');
            $table->text('content');
            $table->string('content_hash', 64);
            $table->decimal('total_tax', 18, 2);
            $table->uuid('closed_by')->nullable();
            $table->timestamp('closed_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'canton', 'year', 'period_type', 'period'], 'payroll_withholding_statements_period');
        });

        $this->insertGenericRows();
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_withholding_statements');
        Schema::dropIfExists('payroll_frontier_years');

        Schema::table('payroll_swiss_settings', function (Blueprint $table): void {
            $table->dropColumn('withholding_debtor_numbers');
        });

        Schema::table('payroll_employee_profiles', function (Blueprint $table): void {
            $table->dropColumn(['birth_place', 'italian_tax_code', 'frontier_regime', 'frontier_status_valid_from', 'frontier_commune_in_zone', 'frontier_returns_daily', 'ccl_zone']);
        });

        Schema::dropIfExists('payroll_legal_parameters');
    }

    private function insertGenericRows(): void
    {
        (new PayrollReferenceSeeder)->legalParameters();
    }
};
