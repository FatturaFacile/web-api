<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Swiss payroll reference data: year-versioned social insurance rate sets, wage types and cantonal
 * withholding tax (Quellensteuer) tariffs. Rows with a null tenant_id are system defaults shared by all
 * tenants; tenant rows override them.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Social insurance rate sets (AHV/IV/EO, ALV, BVG, UVG, KTG, FAK, admin costs)
        if (! Schema::hasTable('hr_ch_rate_sets')) {
            Schema::create('hr_ch_rate_sets', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('code', 60);
                $table->string('name', 150);
                $table->unsignedSmallInteger('year')->index();
                $table->date('valid_from');
                $table->date('valid_to')->nullable();
                $table->jsonb('rates');
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'valid_from']);
                $table->index(['tenant_id', 'code']);
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE hr_ch_rate_sets ADD CONSTRAINT chk_hr_ch_rate_sets_validity CHECK (valid_to IS NULL OR valid_to >= valid_from)');
            }
        }

        // 2. Wage types (Lohnarten) with social insurance subjection flags and ELM mapping
        if (! Schema::hasTable('hr_ch_wage_types')) {
            Schema::create('hr_ch_wage_types', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('code', 20);
                $table->string('name', 150);
                $table->string('category', 30); // salary, allowance, benefit_in_kind, expense, net_deduction
                $table->boolean('is_ahv_subject')->default(true);
                $table->boolean('is_alv_subject')->default(true);
                $table->boolean('is_bvg_subject')->default(true);
                $table->boolean('is_uvg_subject')->default(true);
                $table->boolean('is_qst_subject')->default(true);
                $table->string('elm_code', 60)->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->jsonb('translations')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'code']);
            });
        }

        // 3. Withholding tax (Quellensteuer) tariffs per canton, tariff code and validity
        if (! Schema::hasTable('hr_ch_withholding_tariffs')) {
            Schema::create('hr_ch_withholding_tariffs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->char('canton', 2);
                $table->string('tariff_code', 10);
                $table->date('valid_from');
                $table->date('valid_to')->nullable();
                $table->string('source', 30)->default('manual'); // manual, csv_import, seeder
                $table->string('description', 255)->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['canton', 'tariff_code', 'valid_from']);
                $table->index(['tenant_id', 'canton']);
            });
        }

        // 4. Withholding tax tariff income brackets (monthly taxable income in Rappen)
        if (! Schema::hasTable('hr_ch_withholding_tariff_brackets')) {
            Schema::create('hr_ch_withholding_tariff_brackets', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('withholding_tariff_id')->constrained('hr_ch_withholding_tariffs')->cascadeOnDelete();
                $table->unsignedBigInteger('income_from_rappen');
                $table->unsignedBigInteger('income_to_rappen')->nullable();
                $table->unsignedInteger('rate_ppm'); // parts per million: 1% = 10000
                $table->unsignedBigInteger('fixed_amount_rappen')->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['withholding_tariff_id', 'income_from_rappen'], 'hr_ch_wht_brackets_lookup_idx');
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE hr_ch_withholding_tariff_brackets ADD CONSTRAINT chk_hr_ch_wht_brackets_range CHECK ((income_to_rappen IS NULL OR income_to_rappen >= income_from_rappen) AND rate_ppm <= 1000000)');
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_ch_withholding_tariff_brackets');
        Schema::dropIfExists('hr_ch_withholding_tariffs');
        Schema::dropIfExists('hr_ch_wage_types');
        Schema::dropIfExists('hr_ch_rate_sets');
    }
};
