<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CSRD/ESRS reporting on top of the carbon accounting tables: the catalog of ESRS data points, their value and review
     * status per reporting period, and the supplier-specific emission data requests (Scope 3) that travel through the
     * document requirements of the supplier portal. New tables only; completeness rules live in the application.
     */
    public function up(): void
    {
        if (! Schema::hasTable('esg_esrs_datapoints')) {
            Schema::create('esg_esrs_datapoints', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('code', 40);
                $table->string('standard', 10);
                $table->string('disclosure', 20);
                $table->string('name', 255);
                $table->text('description')->nullable();
                $table->string('data_type', 20)->default('number');
                $table->string('unit', 20)->nullable();
                $table->boolean('is_mandatory')->default(true);
                $table->string('auto_source', 40)->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code']);
                $table->index(['tenant_id', 'standard']);
            });
        }

        if (! Schema::hasTable('esg_esrs_datapoint_values')) {
            Schema::create('esg_esrs_datapoint_values', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('datapoint_id')->constrained('esg_esrs_datapoints')->cascadeOnDelete();
                $table->foreignUuid('reporting_period_id')->constrained('esg_reporting_periods')->cascadeOnDelete();
                $table->string('status', 20)->default('draft');
                $table->decimal('value_number', 22, 4)->nullable();
                $table->text('value_text')->nullable();
                $table->boolean('value_boolean')->nullable();
                $table->string('source', 20)->default('manual');
                $table->text('omission_reason')->nullable();
                $table->text('notes')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['reporting_period_id', 'datapoint_id']);
                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('esg_supplier_data_requests')) {
            Schema::create('esg_supplier_data_requests', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('reporting_period_id')->constrained('esg_reporting_periods')->cascadeOnDelete();
                $table->uuid('supplier_id')->index();
                $table->uuid('document_requirement_id')->nullable();
                $table->uuid('submission_id')->nullable();
                $table->unsignedTinyInteger('scope3_category');
                $table->string('activity_type', 40);
                $table->string('unit', 10);
                $table->decimal('quantity', 22, 4);
                $table->string('status', 20)->default('requested');
                $table->date('due_date')->nullable();
                $table->decimal('reported_co2e_kg', 22, 4)->nullable();
                $table->string('calculation_method', 190)->nullable();
                $table->uuid('emission_factor_id')->nullable();
                $table->uuid('activity_record_id')->nullable();
                $table->text('notes')->nullable();
                $table->uuid('requested_by')->nullable();
                $table->timestamp('received_at')->nullable();
                $table->timestamp('applied_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'reporting_period_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        foreach (['esg_supplier_data_requests', 'esg_esrs_datapoint_values', 'esg_esrs_datapoints'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
