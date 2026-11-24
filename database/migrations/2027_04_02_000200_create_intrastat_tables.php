<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * INTRA lists (elenchi riepilogativi Intrastat): the declarations of each company, their detail rows and the
     * Intrastat data of the products. Amounts and quantities are whole numbers because the lists carry rounded euro and
     * units. New tables only: nothing existing is touched.
     */
    public function up(): void
    {
        Schema::create('intrastat_declarations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('flow', 10);
            $table->unsignedSmallInteger('year');
            $table->char('periodicity', 1);
            $table->unsignedTinyInteger('period');
            $table->unsignedInteger('reference_number');
            $table->unsignedTinyInteger('content_code')->default(0);
            $table->unsignedTinyInteger('special_case_code')->default(0);
            $table->string('obligated_vat_number', 11)->nullable();
            $table->string('delegate_vat_number', 11)->nullable();
            $table->boolean('includes_statistical_data')->default(false);
            $table->boolean('has_annual_flows_above_20_million')->default(false);
            $table->string('status', 20)->default('draft');
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('protocol_number', 30)->nullable();
            $table->foreignUuid('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('generation_report')->nullable();
            $table->text('notes')->nullable();
            $table->string('last_export_file_name', 20)->nullable();
            $table->timestamp('last_exported_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'reference_number']);
            $table->index(['tenant_id', 'flow', 'year', 'period']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE intrastat_declarations ADD CONSTRAINT intrastat_declarations_flow_check CHECK (flow IN ('sales', 'purchases'))");
            DB::statement("ALTER TABLE intrastat_declarations ADD CONSTRAINT intrastat_declarations_periodicity_check CHECK (periodicity IN ('M', 'T'))");
            DB::statement("ALTER TABLE intrastat_declarations ADD CONSTRAINT intrastat_declarations_status_check CHECK (status IN ('draft', 'validated', 'submitted'))");
        }

        Schema::create('intrastat_declaration_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('declaration_id')->constrained('intrastat_declarations')->cascadeOnDelete();
            $table->unsignedTinyInteger('section');
            $table->unsignedInteger('line_number');
            $table->string('partner_country_code', 2)->nullable();
            $table->string('partner_vat_number', 12)->nullable();
            $table->unsignedBigInteger('amount_eur')->nullable();
            $table->unsignedBigInteger('amount_currency')->nullable();
            $table->string('transaction_nature_a', 1)->nullable();
            $table->string('transaction_nature_b', 1)->nullable();
            $table->string('cn8_code', 8)->nullable();
            $table->unsignedBigInteger('net_mass_kg')->nullable();
            $table->unsignedBigInteger('supplementary_units')->nullable();
            $table->unsignedBigInteger('statistical_value_eur')->nullable();
            $table->string('delivery_terms', 1)->nullable();
            $table->string('transport_mode', 1)->nullable();
            $table->string('goods_country_code', 2)->nullable();
            $table->string('origin_country_code', 2)->nullable();
            $table->string('province_code', 2)->nullable();
            $table->string('invoice_number', 15)->nullable();
            $table->date('invoice_date')->nullable();
            $table->string('service_code', 6)->nullable();
            $table->string('supply_mode', 1)->nullable();
            $table->string('payment_method', 1)->nullable();
            $table->string('payment_country_code', 2)->nullable();
            $table->unsignedTinyInteger('corrected_month')->nullable();
            $table->unsignedTinyInteger('corrected_quarter')->nullable();
            $table->unsignedSmallInteger('corrected_year')->nullable();
            $table->string('sign', 1)->nullable();
            $table->string('corrected_customs_section', 6)->nullable();
            $table->unsignedSmallInteger('corrected_protocol_year')->nullable();
            $table->string('corrected_protocol_number', 6)->nullable();
            $table->unsignedInteger('corrected_line_number')->nullable();
            $table->boolean('is_cancellation')->default(false);
            $table->jsonb('source_documents')->nullable();
            $table->timestamps();

            $table->unique(['declaration_id', 'section', 'line_number']);
            $table->index(['tenant_id', 'declaration_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE intrastat_declaration_lines ADD CONSTRAINT intrastat_declaration_lines_section_check CHECK (section BETWEEN 1 AND 4)');
        }

        Schema::create('intrastat_product_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('supply_kind', 10);
            $table->string('cn8_code', 8)->nullable();
            $table->decimal('net_mass_kg', 14, 4)->nullable();
            $table->decimal('supplementary_units_per_unit', 14, 4)->nullable();
            $table->string('service_code', 6)->nullable();
            $table->string('origin_country_code', 2)->nullable();
            $table->string('origin_province_code', 2)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'product_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE intrastat_product_profiles ADD CONSTRAINT intrastat_product_profiles_kind_check CHECK (supply_kind IN ('goods', 'services'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('intrastat_product_profiles');
        Schema::dropIfExists('intrastat_declaration_lines');
        Schema::dropIfExists('intrastat_declarations');
    }
};
