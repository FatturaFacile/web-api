<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Field Service Management (GH-196, GH-197, GH-202): maintenance coverage and preventive rules on the service contract,
 * meter counters on the equipment, idempotent offline-sync keys and dispatch inputs on work orders and part consumptions,
 * and the digital service report with the customer signature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('esm_service_contracts', function (Blueprint $table): void {
            $table->string('coverage_type', 30)->default('parts_and_labor'); // labor_only, parts_and_labor, preventive_only, full_comprehensive
            $table->jsonb('preventive_frequency_rule')->nullable(); // {calendar:{every,unit}, meter:{operating_hours,cycles,lead_percent}, lead_days}
            $table->unsignedInteger('included_visits_per_year')->nullable();
            $table->unsignedInteger('used_visits_current_year')->default(0);
            $table->unsignedSmallInteger('visits_counter_year')->nullable();
            $table->decimal('callout_fee_amount', 18, 2)->default(0.00);
            $table->decimal('hourly_labor_rate', 18, 2)->nullable();
            $table->uuid('recurring_billing_subscription_id')->nullable()->index();
            $table->text('notes')->nullable();
        });

        Schema::table('esm_equipment_passports', function (Blueprint $table): void {
            $table->uuid('service_contract_id')->nullable()->index();
            $table->string('site_name', 150)->nullable();
            $table->string('site_address', 255)->nullable();
            $table->decimal('site_latitude', 10, 7)->nullable();
            $table->decimal('site_longitude', 10, 7)->nullable();
            $table->decimal('current_operating_hours', 12, 2)->default(0);
            $table->unsignedBigInteger('current_cycle_count')->default(0);
            $table->decimal('last_service_operating_hours', 12, 2)->default(0);
            $table->unsignedBigInteger('last_service_cycle_count')->default(0);
            $table->date('last_service_date')->nullable();
            $table->date('next_scheduled_service_date')->nullable()->index();
        });

        Schema::table('esm_work_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('esm_work_orders', 'service_contract_id')) {
                $table->uuid('service_contract_id')->nullable()->index();
            }
            $table->uuid('equipment_passport_id')->nullable()->index();
            $table->jsonb('required_skills')->nullable(); // list of skill names the technician must hold
            $table->jsonb('required_parts')->nullable(); // [{product_id, quantity}] checked against the van stock at dispatch
            $table->timestamp('actual_arrival')->nullable();
            $table->string('client_uuid', 64)->nullable();
            $table->string('preventive_cycle_key', 160)->nullable();

            $table->unique(['tenant_id', 'client_uuid'], 'esm_work_orders_tenant_client_uuid_unique');
            $table->unique(['tenant_id', 'preventive_cycle_key'], 'esm_work_orders_tenant_cycle_key_unique');
        });

        Schema::table('esm_parts_consumptions', function (Blueprint $table): void {
            $table->decimal('unit_price', 18, 2)->default(0.00);
            $table->boolean('is_covered_by_warranty_or_contract')->default(false);
            $table->uuid('stock_movement_id')->nullable()->index();
            $table->uuid('lot_or_serial_id')->nullable()->index();
            $table->string('client_uuid', 64)->nullable();

            $table->unique(['tenant_id', 'client_uuid'], 'esm_parts_consumptions_tenant_client_uuid_unique');
        });

        Schema::create('esm_digital_service_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('company_id')->nullable()->index();
            $table->foreignUuid('work_order_id')->constrained('esm_work_orders')->cascadeOnDelete();
            $table->string('report_number', 60);
            $table->text('work_performed_summary');
            $table->jsonb('checklist_json')->nullable(); // [{item, result: pass|fail|na, notes}]
            $table->decimal('labor_hours', 8, 2)->default(0);
            $table->decimal('travel_hours', 8, 2)->default(0);
            $table->decimal('travel_km', 10, 2)->default(0);
            $table->boolean('apply_callout_fee')->default(false);
            $table->decimal('meter_reading_hours', 12, 2)->nullable();
            $table->unsignedBigInteger('meter_reading_cycles')->nullable();
            $table->string('customer_signer_name', 150);
            $table->string('customer_signer_role', 100)->nullable();
            $table->longText('signature_svg_data')->nullable();
            $table->string('signature_image_path', 255)->nullable();
            $table->string('signature_sha256_hash', 64);
            $table->timestamp('signed_at');
            $table->decimal('gps_latitude', 10, 7)->nullable();
            $table->decimal('gps_longitude', 10, 7)->nullable();
            $table->jsonb('photo_attachments_json')->nullable();
            $table->string('locale', 10)->default('it-IT');
            $table->string('pdf_path', 255)->nullable();
            $table->uuid('pdf_document_id')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->uuid('invoice_id')->nullable()->index();
            $table->string('client_uuid', 64)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'report_number']);
            $table->unique(['tenant_id', 'client_uuid']);
            $table->unique('work_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esm_digital_service_reports');

        Schema::table('esm_parts_consumptions', function (Blueprint $table): void {
            $table->dropUnique('esm_parts_consumptions_tenant_client_uuid_unique');
            $table->dropColumn(['unit_price', 'is_covered_by_warranty_or_contract', 'stock_movement_id', 'lot_or_serial_id', 'client_uuid']);
        });

        Schema::table('esm_work_orders', function (Blueprint $table): void {
            $table->dropUnique('esm_work_orders_tenant_client_uuid_unique');
            $table->dropUnique('esm_work_orders_tenant_cycle_key_unique');
            $table->dropColumn(['equipment_passport_id', 'required_skills', 'required_parts', 'actual_arrival', 'client_uuid', 'preventive_cycle_key']);
        });

        Schema::table('esm_equipment_passports', function (Blueprint $table): void {
            $table->dropColumn(['service_contract_id', 'site_name', 'site_address', 'site_latitude', 'site_longitude', 'current_operating_hours', 'current_cycle_count', 'last_service_operating_hours', 'last_service_cycle_count', 'last_service_date', 'next_scheduled_service_date']);
        });

        Schema::table('esm_service_contracts', function (Blueprint $table): void {
            $table->dropColumn(['coverage_type', 'preventive_frequency_rule', 'included_visits_per_year', 'used_visits_current_year', 'visits_counter_year', 'callout_fee_amount', 'hourly_labor_rate', 'recurring_billing_subscription_id', 'notes']);
        });
    }
};
