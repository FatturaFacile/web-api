<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #543 (Mod 297) — olive oil mills and grain mills: crop deliveries with the weighbridge and the milling booking, the milling cycles with
 * yield and lab data, the stainless steel tanks, the SIAN telematic oil register, the in-kind (molenda) settlements and the by-product register.
 * Tenant isolation is the application level TenantScope (tenant_id on every row), like every other table. Dialect-agnostic (SQLite and PostgreSQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->deliveries();
        $this->tanks();
        $this->cycles();
        $this->sianMovements();
        $this->settlements();
        $this->byproducts();
    }

    public function down(): void
    {
        foreach (['mill_byproduct_movements', 'mill_settlements', 'mill_sian_movements', 'mill_milling_cycles', 'mill_tanks', 'mill_deliveries'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function deliveries(): void
    {
        if (Schema::hasTable('mill_deliveries')) {
            return;
        }

        Schema::create('mill_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedSmallInteger('campaign_year');
            $table->string('lot_code', 40);
            $table->dateTime('delivered_at');
            $table->string('processing_mode', 20);
            $table->string('commodity', 10);
            $table->string('grain_type', 40)->nullable();
            $table->foreignUuid('farmer_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('cultivar', 80)->nullable();
            $table->string('origin_municipality', 120)->nullable();
            $table->string('cadastral_reference', 80)->nullable();
            $table->string('sian_origin_code', 40)->nullable();
            $table->string('certification', 20)->default('EVO');
            $table->decimal('gross_weight_kg', 14, 3)->nullable();
            $table->decimal('tare_kg', 14, 3)->default(0);
            $table->unsignedInteger('crate_count')->default(0);
            $table->decimal('crate_unit_tare_kg', 8, 3)->default(0);
            $table->decimal('net_weight_kg', 14, 3)->nullable();
            $table->string('weighbridge_ticket', 60)->nullable();
            $table->text('scale_payload')->nullable();
            $table->dateTime('scale_read_at')->nullable();
            $table->dateTime('scheduled_at')->nullable();
            $table->unsignedSmallInteger('estimated_duration_minutes')->nullable();
            $table->string('hopper_line', 30)->nullable();
            $table->string('status', 20)->default('BOOKED');
            $table->dateTime('sms_reminder_sent_at')->nullable();
            $table->string('sms_recipient_phone', 40)->nullable();
            $table->string('sms_status', 20)->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'lot_code'], 'mdl_lot_uq');
            $table->index(['tenant_id', 'campaign_year', 'status'], 'mdl_campaign_idx');
            $table->index(['tenant_id', 'scheduled_at', 'hopper_line'], 'mdl_slot_idx');
            $table->index(['tenant_id', 'farmer_contact_id'], 'mdl_farmer_idx');
        });
    }

    private function tanks(): void
    {
        if (Schema::hasTable('mill_tanks')) {
            return;
        }

        Schema::create('mill_tanks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('description', 160)->nullable();
            $table->string('material', 30)->default('INOX_AISI_304');
            $table->decimal('capacity_kg', 14, 3);
            $table->decimal('capacity_l', 14, 3);
            $table->decimal('stock_kg', 14, 3)->default(0);
            $table->decimal('stock_l', 14, 3)->default(0);
            $table->string('category', 20)->nullable();
            $table->string('origin_designation', 20)->nullable();
            $table->text('lot_composition')->nullable();
            $table->decimal('temperature_c', 5, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('grid_row')->default(1);
            $table->unsignedSmallInteger('grid_col')->default(1);
            $table->string('zone_label', 60)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code'], 'mtk_code_uq');
            $table->index(['tenant_id', 'is_active', 'category'], 'mtk_category_idx');
        });
    }

    private function cycles(): void
    {
        if (Schema::hasTable('mill_milling_cycles')) {
            return;
        }

        Schema::create('mill_milling_cycles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('delivery_id')->constrained('mill_deliveries')->restrictOnDelete();
            $table->string('line_code', 30)->nullable();
            $table->string('status', 20)->default('IN_PROGRESS');
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->decimal('malaxation_temperature_c', 5, 2)->nullable();
            $table->decimal('water_added_l', 10, 2)->default(0);
            $table->decimal('input_net_kg', 14, 3);
            $table->decimal('output_kg', 14, 3)->default(0);
            $table->decimal('output_liters', 14, 3)->default(0);
            $table->decimal('density_kg_per_l', 6, 4)->default(0.916);
            $table->decimal('yield_percent', 7, 2)->default(0);
            $table->boolean('cold_extraction')->default(false);
            $table->decimal('acidity_pct', 5, 2)->nullable();
            $table->decimal('peroxides', 6, 2)->nullable();
            $table->decimal('polyphenols_mg_kg', 8, 2)->nullable();
            $table->text('panel_notes')->nullable();
            $table->string('quality_class', 20)->nullable();
            $table->text('quality_warnings')->nullable();
            $table->decimal('sansa_kg', 14, 3)->default(0);
            $table->decimal('nocciolino_kg', 14, 3)->default(0);
            $table->decimal('vegetation_water_l', 14, 3)->default(0);
            $table->decimal('bran_kg', 14, 3)->default(0);
            $table->decimal('pickup_kg', 14, 3)->default(0);
            $table->decimal('molenda_kg', 14, 3)->default(0);
            $table->decimal('mill_stock_kg', 14, 3)->default(0);
            $table->foreignUuid('target_tank_id')->nullable()->constrained('mill_tanks')->nullOnDelete();
            $table->dateTime('completed_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'delivery_id'], 'mmc_delivery_uq');
            $table->index(['tenant_id', 'status', 'line_code'], 'mmc_status_idx');
            $table->index(['tenant_id', 'started_at'], 'mmc_started_idx');
        });
    }

    private function sianMovements(): void
    {
        if (Schema::hasTable('mill_sian_movements')) {
            return;
        }

        Schema::create('mill_sian_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedInteger('progressive');
            $table->date('operation_date');
            $table->date('transmission_deadline');
            $table->string('causal', 5);
            $table->foreignUuid('from_tank_id')->nullable()->constrained('mill_tanks')->nullOnDelete();
            $table->foreignUuid('to_tank_id')->nullable()->constrained('mill_tanks')->nullOnDelete();
            $table->foreignUuid('counterparty_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('counterparty_name', 160)->nullable();
            $table->string('counterparty_cuaa', 20)->nullable();
            $table->decimal('quantity_kg', 14, 3);
            $table->decimal('quantity_l', 14, 3);
            $table->string('category', 20)->nullable();
            $table->string('origin_designation', 20)->nullable();
            $table->string('lot_code', 60)->nullable();
            $table->string('bottle_format', 10)->nullable();
            $table->unsignedInteger('bottle_count')->nullable();
            $table->string('package_lot', 60)->nullable();
            $table->date('best_before')->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->string('sian_protocol', 80)->nullable();
            $table->longText('payload_snapshot')->nullable();
            $table->dateTime('transmitted_at')->nullable();
            $table->string('source_type', 40)->nullable();
            $table->uuid('source_id')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'progressive'], 'msm_progressive_uq');
            $table->index(['tenant_id', 'status', 'transmission_deadline'], 'msm_deadline_idx');
            $table->index(['tenant_id', 'operation_date'], 'msm_date_idx');
            $table->index(['tenant_id', 'source_type', 'source_id'], 'msm_source_idx');
        });
    }

    private function settlements(): void
    {
        if (Schema::hasTable('mill_settlements')) {
            return;
        }

        Schema::create('mill_settlements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('delivery_id')->constrained('mill_deliveries')->restrictOnDelete();
            $table->foreignUuid('cycle_id')->constrained('mill_milling_cycles')->restrictOnDelete();
            $table->string('tariff_mode', 30);
            $table->decimal('tariff_value', 14, 4)->default(0);
            $table->decimal('service_net', 14, 2)->default(0);
            $table->decimal('service_vat_rate', 6, 2)->default(0);
            $table->decimal('service_vat', 14, 2)->default(0);
            $table->decimal('service_total', 14, 2)->default(0);
            $table->string('settlement_mode', 30);
            $table->decimal('molenda_percent', 7, 3)->nullable();
            $table->decimal('retained_kg', 14, 3)->default(0);
            $table->decimal('oil_price_per_kg', 12, 4)->default(0);
            $table->decimal('retained_value_net', 14, 2)->default(0);
            $table->decimal('purchase_vat_rate', 6, 2)->default(0);
            $table->decimal('purchase_vat', 14, 2)->default(0);
            $table->decimal('purchase_total', 14, 2)->default(0);
            $table->decimal('cash_balance', 14, 2)->default(0);
            $table->decimal('compensation_amount', 14, 2)->default(0);
            $table->foreignUuid('tank_id')->nullable()->constrained('mill_tanks')->nullOnDelete();
            $table->uuid('service_invoice_id')->nullable()->index();
            $table->uuid('purchase_doc_id')->nullable()->index();
            $table->uuid('sian_movement_id')->nullable();
            $table->string('status', 20)->default('SETTLED');
            $table->dateTime('settled_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'cycle_id'], 'mst_cycle_uq');
            $table->index(['tenant_id', 'delivery_id'], 'mst_delivery_idx');
            $table->index(['tenant_id', 'settled_at'], 'mst_settled_idx');
        });
    }

    private function byproducts(): void
    {
        if (Schema::hasTable('mill_byproduct_movements')) {
            return;
        }

        Schema::create('mill_byproduct_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('byproduct_type', 20);
            $table->date('movement_date');
            $table->decimal('quantity', 14, 3);
            $table->string('unit', 5);
            $table->foreignUuid('delivery_id')->nullable()->constrained('mill_deliveries')->nullOnDelete();
            $table->foreignUuid('cycle_id')->nullable()->constrained('mill_milling_cycles')->nullOnDelete();
            $table->foreignUuid('counterparty_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('counterparty_name', 160)->nullable();
            $table->string('destination_type', 30)->nullable();
            $table->string('transport_document', 80)->nullable();
            $table->string('cadastral_parcel', 80)->nullable();
            $table->decimal('parcel_area_ha', 10, 4)->nullable();
            $table->decimal('m3_per_ha', 10, 3)->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'byproduct_type', 'movement_date'], 'mbp_type_idx');
            $table->index(['tenant_id', 'cadastral_parcel'], 'mbp_parcel_idx');
        });
    }
};
