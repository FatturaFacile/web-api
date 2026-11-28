<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mod 298 — Dairy, cooperative creamery and alpine pasture vertical (milk collection and quality, vat batches, DOP/AOP wheel
 * aging, SIAN / DBMilch.ch compliance). Tenant isolation is enforced by the BelongsToTenant global scope (nullable tenant_id,
 * null = global reference row such as pricing grid templates and weight-loss curves). Dialect-agnostic: SQLite and PostgreSQL.
 */
return new class extends Migration
{
    private const array Tables = [
        'dairy_recall_reports', 'dairy_regulatory_submissions', 'dairy_aging_care_logs', 'dairy_cheese_aging_wheels', 'dairy_aging_racks',
        'dairy_vat_batch_milk_draws', 'dairy_vat_batch_tank_usages', 'dairy_cheese_vat_batches', 'dairy_monthly_settlements',
        'dairy_milk_quality_tests', 'dairy_milk_deliveries', 'dairy_weight_loss_curves', 'dairy_milk_pricing_grids', 'dairy_milk_tanks', 'dairy_breeders',
    ];

    public function up(): void
    {
        $this->base();
        $this->milk();
        $this->production();
        $this->aging();
        $this->compliance();
    }

    public function down(): void
    {
        foreach (self::Tables as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function head(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
    }

    private function audit(Blueprint $table): void
    {
        $table->uuid('created_by')->nullable();
        $table->uuid('updated_by')->nullable();
        $table->uuid('deleted_by')->nullable();
        $table->timestamps();
        $table->softDeletes();
    }

    private function base(): void
    {
        if (! Schema::hasTable('dairy_breeders')) {
            Schema::create('dairy_breeders', function (Blueprint $table): void {
                $this->head($table);
                $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
                $table->string('code', 40);
                $table->string('name', 200);
                $table->string('jurisdiction', 2); // CH, IT
                $table->string('farm_code', 40)->nullable();
                $table->string('tvd_number', 40)->nullable(); // CH Tierverkehrsdatenbank
                $table->string('bdm_number', 40)->nullable(); // CH DBMilch producer number
                $table->string('asl_code', 40)->nullable(); // IT ASL stable code
                $table->string('cuaa', 20)->nullable(); // IT CUAA
                $table->string('municipality', 120)->nullable();
                $table->string('canton_or_province', 10)->nullable();
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $this->audit($table);
                $table->index(['tenant_id', 'code'], 'dairy_br_code_ix');
                $table->index(['tenant_id', 'jurisdiction'], 'dairy_br_jur_ix');
            });
        }

        if (! Schema::hasTable('dairy_milk_tanks')) {
            Schema::create('dairy_milk_tanks', function (Blueprint $table): void {
                $this->head($table);
                $table->string('code', 40);
                $table->string('name', 200);
                $table->decimal('capacity_liters', 12, 3)->default(0);
                $table->string('status', 20)->default('active');
                $table->dateTime('last_cleaned_at')->nullable();
                $this->audit($table);
                $table->index(['tenant_id', 'code'], 'dairy_tk_code_ix');
            });
        }

        if (! Schema::hasTable('dairy_milk_pricing_grids')) {
            Schema::create('dairy_milk_pricing_grids', function (Blueprint $table): void {
                $this->head($table);
                $table->string('name', 200);
                $table->string('jurisdiction', 2);
                $table->string('cheese_type', 40)->nullable();
                $table->decimal('base_price_per_kg', 10, 4)->default(0);
                $table->decimal('cheese_milk_supplement_per_kg', 10, 4)->default(0);
                $table->decimal('dop_premium_per_kg', 10, 4)->default(0);
                $table->date('valid_from');
                $table->date('valid_to')->nullable();
                $table->boolean('is_active')->default(true);
                $table->json('tiers')->nullable();
                $this->audit($table);
                $table->index(['tenant_id', 'jurisdiction'], 'dairy_pg_jur_ix');
            });
        }

        if (! Schema::hasTable('dairy_weight_loss_curves')) {
            Schema::create('dairy_weight_loss_curves', function (Blueprint $table): void {
                $this->head($table);
                $table->string('cheese_type', 40);
                $table->string('name', 200);
                $table->json('points');
                $this->audit($table);
                $table->index(['tenant_id', 'cheese_type'], 'dairy_wlc_type_ix');
            });
        }
    }

    private function milk(): void
    {
        if (! Schema::hasTable('dairy_monthly_settlements')) {
            Schema::create('dairy_monthly_settlements', function (Blueprint $table): void {
                $this->head($table);
                $table->foreignUuid('breeder_id')->constrained('dairy_breeders')->cascadeOnDelete();
                $table->string('period', 7);
                $table->foreignUuid('grid_id')->constrained('dairy_milk_pricing_grids')->restrictOnDelete();
                $table->string('status', 20)->default('draft');
                $table->decimal('total_kg', 14, 3)->default(0);
                $table->decimal('total_liters', 14, 3)->default(0);
                $table->decimal('avg_fat_pct', 6, 3)->default(0);
                $table->decimal('avg_protein_pct', 6, 3)->default(0);
                $table->decimal('base_amount', 14, 2)->default(0);
                $table->decimal('quality_adjustment', 14, 2)->default(0);
                $table->decimal('supplement_amount', 14, 2)->default(0);
                $table->decimal('penalty_amount', 14, 2)->default(0);
                $table->decimal('gross_amount', 14, 2)->default(0);
                $table->decimal('vat_amount', 14, 2)->default(0);
                $table->decimal('net_amount', 14, 2)->default(0);
                $table->string('currency', 3)->default('EUR');
                $table->uuid('document_id')->nullable();
                $table->json('lines')->nullable();
                $this->audit($table);
                $table->index(['tenant_id', 'period'], 'dairy_ms_period_ix');
                $table->index(['tenant_id', 'breeder_id', 'period'], 'dairy_ms_breeder_period_ix');
            });
        }

        if (! Schema::hasTable('dairy_milk_deliveries')) {
            Schema::create('dairy_milk_deliveries', function (Blueprint $table): void {
                $this->head($table);
                $table->foreignUuid('breeder_id')->constrained('dairy_breeders')->cascadeOnDelete();
                $table->foreignUuid('tank_id')->constrained('dairy_milk_tanks')->restrictOnDelete();
                $table->string('route_code', 40)->nullable();
                $table->string('tanker_plate', 20)->nullable();
                $table->date('collection_date');
                $table->string('session', 10); // morning, evening
                $table->decimal('liters', 12, 3);
                $table->decimal('density', 6, 4)->default(1.032);
                $table->decimal('kg', 12, 3);
                $table->decimal('remaining_liters', 12, 3)->default(0);
                $table->decimal('remaining_kg', 12, 3)->default(0);
                $table->decimal('temperature_c', 4, 1)->nullable();
                $table->string('status', 20)->default('registered');
                $table->uuid('monthly_settlement_id')->nullable();
                $table->text('notes')->nullable();
                $this->audit($table);
                $table->index(['tenant_id', 'collection_date'], 'dairy_dl_date_ix');
                $table->index(['tenant_id', 'tank_id', 'collection_date'], 'dairy_dl_tank_ix');
                $table->index(['tenant_id', 'breeder_id', 'collection_date'], 'dairy_dl_breeder_ix');
            });
        }

        if (! Schema::hasTable('dairy_milk_quality_tests')) {
            Schema::create('dairy_milk_quality_tests', function (Blueprint $table): void {
                $this->head($table);
                $table->foreignUuid('breeder_id')->constrained('dairy_breeders')->cascadeOnDelete();
                $table->foreignUuid('delivery_id')->nullable()->constrained('dairy_milk_deliveries')->nullOnDelete();
                $table->date('sample_date');
                $table->date('period_start')->nullable();
                $table->date('period_end')->nullable();
                $table->decimal('fat_pct', 6, 3)->nullable();
                $table->decimal('protein_pct', 6, 3)->nullable();
                $table->decimal('casein_pct', 6, 3)->nullable();
                $table->decimal('urea_mg_l', 8, 2)->nullable();
                $table->unsignedBigInteger('tbc_cfu_ml')->nullable();
                $table->unsignedBigInteger('scc_cells_ml')->nullable();
                $table->decimal('freezing_point_c', 6, 4)->nullable();
                $table->string('inhibitors', 15)->default('not_tested');
                $table->string('laboratory', 120)->nullable();
                $table->string('source_format', 20)->nullable();
                $this->audit($table);
                $table->index(['tenant_id', 'breeder_id', 'sample_date'], 'dairy_qt_breeder_ix');
            });
        }
    }

    private function production(): void
    {
        if (! Schema::hasTable('dairy_cheese_vat_batches')) {
            Schema::create('dairy_cheese_vat_batches', function (Blueprint $table): void {
                $this->head($table);
                $table->string('code', 40);
                $table->string('cheese_type', 40);
                $table->string('status', 20)->default('open');
                $table->date('production_date');
                $table->string('vat_number', 40)->nullable();
                $table->decimal('milk_kg', 12, 3)->default(0);
                $table->string('starter_culture', 120)->nullable();
                $table->decimal('starter_amount', 10, 3)->nullable();
                $table->string('rennet_type', 120)->nullable();
                $table->decimal('rennet_ml', 10, 3)->nullable();
                $table->json('ph_log')->nullable();
                $table->decimal('curd_yield_kg', 12, 3)->nullable();
                $table->decimal('fresh_yield_24h_kg', 12, 3)->nullable();
                $table->decimal('fresh_yield_24h_pct', 8, 3)->nullable();
                $table->decimal('final_aged_yield_kg', 12, 3)->nullable();
                $table->decimal('final_aged_yield_pct', 8, 3)->nullable();
                $table->string('plaque_prefix', 20)->nullable();
                $table->unsignedInteger('plaque_from')->nullable();
                $table->unsignedInteger('plaque_to')->nullable();
                $table->unsignedInteger('wheels_count')->default(0);
                $this->audit($table);
                $table->index(['tenant_id', 'code'], 'dairy_vb_code_ix');
                $table->index(['tenant_id', 'production_date'], 'dairy_vb_date_ix');
            });
        }

        if (! Schema::hasTable('dairy_vat_batch_tank_usages')) {
            Schema::create('dairy_vat_batch_tank_usages', function (Blueprint $table): void {
                $this->head($table);
                $table->foreignUuid('vat_batch_id')->constrained('dairy_cheese_vat_batches')->cascadeOnDelete();
                $table->foreignUuid('tank_id')->constrained('dairy_milk_tanks')->restrictOnDelete();
                $table->decimal('liters', 12, 3);
                $table->decimal('kg', 12, 3);
                $this->audit($table);
                $table->index(['tenant_id', 'vat_batch_id'], 'dairy_vu_batch_ix');
            });
        }

        if (! Schema::hasTable('dairy_vat_batch_milk_draws')) {
            Schema::create('dairy_vat_batch_milk_draws', function (Blueprint $table): void {
                $this->head($table);
                $table->foreignUuid('vat_batch_id')->constrained('dairy_cheese_vat_batches')->cascadeOnDelete();
                $table->foreignUuid('tank_id')->constrained('dairy_milk_tanks')->restrictOnDelete();
                $table->foreignUuid('delivery_id')->constrained('dairy_milk_deliveries')->restrictOnDelete();
                $table->foreignUuid('breeder_id')->constrained('dairy_breeders')->restrictOnDelete();
                $table->decimal('liters', 12, 3);
                $table->decimal('kg', 12, 3);
                $this->audit($table);
                $table->index(['tenant_id', 'vat_batch_id'], 'dairy_vd_batch_ix');
                $table->index(['tenant_id', 'delivery_id'], 'dairy_vd_delivery_ix');
                $table->index(['tenant_id', 'breeder_id'], 'dairy_vd_breeder_ix');
            });
        }
    }

    private function aging(): void
    {
        if (! Schema::hasTable('dairy_aging_racks')) {
            Schema::create('dairy_aging_racks', function (Blueprint $table): void {
                $this->head($table);
                $table->string('room', 60);
                $table->string('rack', 60);
                $table->string('shelf', 60);
                $table->unsignedSmallInteger('level')->default(1);
                $table->unsignedInteger('capacity_wheels')->default(0);
                $this->audit($table);
                $table->index(['tenant_id', 'room', 'rack'], 'dairy_rk_room_ix');
            });
        }

        if (! Schema::hasTable('dairy_cheese_aging_wheels')) {
            Schema::create('dairy_cheese_aging_wheels', function (Blueprint $table): void {
                $this->head($table);
                $table->string('serial', 60);
                $table->foreignUuid('vat_batch_id')->constrained('dairy_cheese_vat_batches')->cascadeOnDelete();
                $table->foreignUuid('rack_id')->nullable()->constrained('dairy_aging_racks')->nullOnDelete();
                $table->unsignedInteger('position')->nullable();
                $table->string('status', 20)->default('brine');
                $table->string('grade', 20)->default('ungraded');
                $table->boolean('branded')->default(false);
                $table->dateTime('branded_at')->nullable();
                $table->decimal('initial_weight_kg', 8, 3);
                $table->decimal('measured_weight_kg', 8, 3)->nullable();
                $table->dateTime('last_weighed_at')->nullable();
                $table->dateTime('sold_at')->nullable();
                $table->decimal('sold_weight_kg', 8, 3)->nullable();
                $table->decimal('sale_variance_kg', 8, 3)->nullable();
                $table->uuid('customer_id')->nullable();
                $table->string('customer_name', 200)->nullable();
                $table->string('invoice_ref', 80)->nullable();
                $table->text('grading_notes')->nullable();
                $this->audit($table);
                $table->index(['tenant_id', 'serial'], 'dairy_wh_serial_ix');
                $table->index(['tenant_id', 'status'], 'dairy_wh_status_ix');
                $table->index(['tenant_id', 'vat_batch_id'], 'dairy_wh_batch_ix');
                $table->index(['tenant_id', 'rack_id'], 'dairy_wh_rack_ix');
            });
        }

        if (! Schema::hasTable('dairy_aging_care_logs')) {
            Schema::create('dairy_aging_care_logs', function (Blueprint $table): void {
                $this->head($table);
                $table->string('care_type', 20);
                $table->string('status', 20)->default('scheduled');
                $table->string('scope', 10); // wheel, shelf, rack, batch
                $table->uuid('target_id');
                $table->date('scheduled_for');
                $table->dateTime('executed_at')->nullable();
                $table->unsignedInteger('wheels_affected')->default(0);
                $table->string('performed_by', 200)->nullable();
                $table->text('notes')->nullable();
                $this->audit($table);
                $table->index(['tenant_id', 'status', 'scheduled_for'], 'dairy_cl_status_ix');
                $table->index(['tenant_id', 'scope', 'target_id'], 'dairy_cl_target_ix');
            });
        }
    }

    private function compliance(): void
    {
        if (! Schema::hasTable('dairy_regulatory_submissions')) {
            Schema::create('dairy_regulatory_submissions', function (Blueprint $table): void {
                $this->head($table);
                $table->string('authority', 10); // dbmilch, sian
                $table->string('period', 7);
                $table->string('status', 15)->default('generated');
                $table->string('format', 5);
                $table->unsignedInteger('record_count')->default(0);
                $table->string('checksum', 64)->nullable();
                $table->dateTime('generated_at');
                $table->dateTime('submitted_at')->nullable();
                $table->string('file_name', 200)->nullable();
                $table->json('validation_errors')->nullable();
                $table->longText('payload')->nullable();
                $this->audit($table);
                $table->index(['tenant_id', 'authority', 'period'], 'dairy_rs_period_ix');
            });
        }

        if (! Schema::hasTable('dairy_recall_reports')) {
            Schema::create('dairy_recall_reports', function (Blueprint $table): void {
                $this->head($table);
                $table->string('source_type', 15);
                $table->uuid('source_id');
                $table->text('reason');
                $table->boolean('applied')->default(false);
                $table->dateTime('generated_at');
                $table->json('payload')->nullable();
                $this->audit($table);
                $table->index(['tenant_id', 'source_type', 'source_id'], 'dairy_rr_source_ix');
            });
        }
    }
};
