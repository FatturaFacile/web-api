<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mod 296 — Beekeeping and honey production. Tenant isolation is enforced by the BelongsToTenant global scope (nullable
 * tenant_id like the rest of the schema). Dialect-agnostic: runs identically on SQLite and PostgreSQL.
 */
return new class extends Migration
{
    private const array Tables = [
        'bee_jarring_runs', 'bee_super_intakes', 'bee_extraction_batches', 'bee_census_declarations', 'bee_nomadism_movements',
        'bee_varroa_counts', 'bee_varroa_treatments', 'bee_inspections', 'bee_hives', 'bee_apiaries', 'bee_tax_profiles',
    ];

    public function up(): void
    {
        $this->apiaries();
        $this->hives();
        $this->inspections();
        $this->varroa();
        $this->nomadism();
        $this->census();
        $this->honey();
        $this->taxProfiles();
    }

    public function down(): void
    {
        foreach (self::Tables as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function base(Blueprint $table): void
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

    private function apiaries(): void
    {
        Schema::create('bee_apiaries', function (Blueprint $table): void {
            $this->base($table);
            $table->string('code', 40);
            $table->string('name', 200);
            $table->string('country', 2); // IT, CH
            $table->string('municipality', 120)->nullable();
            $table->string('province_canton', 4)->nullable();
            $table->string('registry_code', 60)->nullable(); // BDA apiary code (IT) / Bienenstand-Nr. TVD (CH)
            $table->string('apiary_type', 20)->default('stationary'); // stationary, nomadic
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('altitude_m')->nullable();
            $table->unsignedInteger('foraging_radius_m')->default(3000);
            $table->text('flora_notes')->nullable();
            $table->text('blooming_calendar')->nullable();
            $table->text('weather_notes')->nullable();
            $table->boolean('is_active')->default(true);
            $this->audit($table);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'is_active']);
        });
    }

    private function hives(): void
    {
        Schema::create('bee_hives', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('apiary_id')->constrained('bee_apiaries')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('qr_token', 64)->unique();
            $table->string('hive_type', 30); // dadant_blatt, langstroth, top_bar, nucleus, mating_nucleus
            $table->string('origin', 30)->default('split'); // swarm_capture, split, purchase
            $table->string('status', 20)->default('active'); // active, orphaned, queenless_cell, united, dead, sold
            $table->boolean('is_nucleus')->default(false);
            $table->unsignedSmallInteger('queen_year')->nullable();
            $table->string('queen_marking_color', 10)->nullable();
            $table->boolean('queen_clipped')->default(false);
            $table->string('queen_genetic_line', 30)->nullable();
            $table->string('queen_breeder', 120)->nullable();
            $table->string('queen_pedigree_ref', 120)->nullable();
            $table->string('queen_status', 20)->default('present_laying'); // present_laying, virgin, supersedure, missing
            $table->unsignedSmallInteger('brood_frames')->default(0);
            $table->unsignedSmallInteger('store_frames')->default(0);
            $table->unsignedSmallInteger('supers_count')->default(0);
            $table->unsignedTinyInteger('strength_score')->default(3);
            $table->string('temperament', 20)->nullable();
            $table->date('installed_on')->nullable();
            $table->text('notes')->nullable();
            $this->audit($table);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'apiary_id', 'status']);
        });
    }

    private function inspections(): void
    {
        Schema::create('bee_inspections', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('hive_id')->constrained('bee_hives')->cascadeOnDelete();
            $table->foreignUuid('apiary_id')->constrained('bee_apiaries')->cascadeOnDelete();
            $table->dateTime('inspected_at');
            $table->uuid('operator_id')->nullable();
            $table->string('weather', 30)->nullable();
            $table->decimal('temperature_c', 4, 1)->nullable();
            $table->boolean('queen_seen')->default(false);
            $table->boolean('eggs_present')->default(false);
            $table->boolean('larvae_present')->default(false);
            $table->boolean('capped_brood_present')->default(false);
            $table->unsignedSmallInteger('brood_frames')->default(0);
            $table->unsignedSmallInteger('store_frames')->default(0);
            $table->unsignedSmallInteger('supers_added')->default(0);
            $table->unsignedSmallInteger('supers_removed')->default(0);
            $table->unsignedSmallInteger('swarm_cells_found')->default(0);
            $table->unsignedSmallInteger('swarm_cells_destroyed')->default(0);
            $table->string('feeding_type', 20)->nullable(); // syrup, candy
            $table->decimal('feeding_kg', 6, 2)->nullable();
            $table->unsignedTinyInteger('strength_score')->nullable();
            $table->text('observations')->nullable();
            $table->text('alerts')->nullable(); // JSON list of alert codes
            $this->audit($table);
            $table->index(['tenant_id', 'hive_id', 'inspected_at']);
        });
    }

    private function varroa(): void
    {
        Schema::create('bee_varroa_treatments', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('apiary_id')->constrained('bee_apiaries')->cascadeOnDelete();
            $table->boolean('whole_apiary')->default(true);
            $table->text('hive_ids')->nullable(); // JSON list of the treated hives when the treatment is not on the whole apiary
            $table->date('treatment_date');
            $table->date('treatment_end_date')->nullable();
            $table->string('product', 40);
            $table->string('active_substance', 40);
            $table->string('commercial_name', 120)->nullable();
            $table->string('drug_batch', 60)->nullable();
            $table->string('prescription_ref', 80)->nullable();
            $table->string('veterinarian', 120)->nullable();
            $table->string('dosage', 80)->nullable();
            $table->string('application_method', 40)->nullable();
            $table->unsignedSmallInteger('withdrawal_days')->default(0);
            $table->date('withdrawal_end_date');
            $table->text('notes')->nullable();
            $this->audit($table);
            $table->index(['tenant_id', 'apiary_id', 'withdrawal_end_date']);
        });

        Schema::create('bee_varroa_counts', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('hive_id')->constrained('bee_hives')->cascadeOnDelete();
            $table->foreignUuid('treatment_id')->nullable()->constrained('bee_varroa_treatments')->nullOnDelete();
            $table->date('counted_on');
            $table->string('count_type', 20)->default('natural'); // natural, post_treatment
            $table->decimal('mites_per_day', 8, 2);
            $this->audit($table);
            $table->index(['tenant_id', 'hive_id', 'counted_on']);
        });
    }

    private function nomadism(): void
    {
        Schema::create('bee_nomadism_movements', function (Blueprint $table): void {
            $this->base($table);
            $table->string('document_number', 40);
            $table->string('kind', 20)->default('nomadism'); // nomadism, sale, purchase
            $table->foreignUuid('origin_apiary_id')->constrained('bee_apiaries')->cascadeOnDelete();
            $table->foreignUuid('destination_apiary_id')->nullable()->constrained('bee_apiaries')->nullOnDelete();
            $table->string('destination_label', 200)->nullable();
            $table->string('destination_registry_code', 60)->nullable();
            $table->string('counterpart', 200)->nullable();
            $table->string('target_flora', 40)->nullable();
            $table->string('subject', 20)->default('hives'); // hives, swarms, nuclei, queens
            $table->unsignedSmallInteger('quantity');
            $table->string('vehicle_plate', 20)->nullable();
            $table->date('departure_date');
            $table->date('arrival_date')->nullable();
            $table->date('return_date')->nullable();
            $table->string('status', 20)->default('planned'); // planned, in_transit, completed, cancelled
            $table->text('notes')->nullable();
            $this->audit($table);
            $table->unique(['tenant_id', 'document_number']);
        });
    }

    private function census(): void
    {
        Schema::create('bee_census_declarations', function (Blueprint $table): void {
            $this->base($table);
            $table->unsignedSmallInteger('census_year');
            $table->string('country', 2);
            $table->string('status', 20)->default('draft'); // draft, submitted
            $table->date('window_start');
            $table->date('window_end');
            $table->unsignedInteger('total_hives')->default(0);
            $table->unsignedInteger('total_nuclei')->default(0);
            $table->text('snapshot'); // JSON: per apiary hives and nuclei
            $table->dateTime('generated_at');
            $table->dateTime('submitted_at')->nullable();
            $table->string('submission_reference', 80)->nullable();
            $this->audit($table);
            $table->unique(['tenant_id', 'census_year', 'country']);
        });
    }

    private function honey(): void
    {
        Schema::create('bee_extraction_batches', function (Blueprint $table): void {
            $this->base($table);
            $table->string('lot_code', 40);
            $table->date('extracted_on');
            $table->string('botanical_origin', 40);
            $table->decimal('extracted_kg', 10, 2);
            $table->unsignedSmallInteger('hives_harvested')->default(0);
            $table->string('tank_code', 40)->nullable(); // maturator or drum
            $table->date('decanted_on')->nullable();
            $table->decimal('moisture_pct', 4, 1)->nullable();
            $table->decimal('hmf_mg_kg', 6, 1)->nullable();
            $table->text('organoleptic_notes')->nullable();
            $table->text('process_notes')->nullable();
            $this->audit($table);
            $table->unique(['tenant_id', 'lot_code']);
        });

        Schema::create('bee_super_intakes', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('apiary_id')->constrained('bee_apiaries')->cascadeOnDelete();
            $table->foreignUuid('extraction_batch_id')->nullable()->constrained('bee_extraction_batches')->nullOnDelete();
            $table->date('harvested_on');
            $table->string('botanical_origin', 40);
            $table->unsignedSmallInteger('supers_count');
            $table->decimal('gross_weight_kg', 10, 2);
            $table->decimal('net_weight_kg', 10, 2);
            $table->text('notes')->nullable();
            $this->audit($table);
            $table->index(['tenant_id', 'apiary_id', 'harvested_on']);
        });

        Schema::create('bee_jarring_runs', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('extraction_batch_id')->constrained('bee_extraction_batches')->cascadeOnDelete();
            $table->string('jar_lot_code', 50);
            $table->date('jarred_on');
            $table->unsignedInteger('jar_format_g');
            $table->unsignedInteger('jars_count');
            $table->decimal('net_kg', 10, 2);
            $table->date('best_before');
            $table->string('origin_country', 2);
            $table->uuid('warehouse_id')->nullable();
            $table->uuid('jar_product_id')->nullable();
            $table->uuid('cap_product_id')->nullable();
            $table->uuid('label_product_id')->nullable();
            $table->text('stock_movements')->nullable(); // JSON list of the stock movement ids booked
            $this->audit($table);
            $table->unique(['tenant_id', 'jar_lot_code']);
        });
    }

    private function taxProfiles(): void
    {
        Schema::create('bee_tax_profiles', function (Blueprint $table): void {
            $this->base($table);
            $table->string('country', 2);
            $table->string('regime', 30); // special_agricultural, exemption, ordinary (IT); standard (CH)
            $table->text('compensation_overrides')->nullable(); // JSON product => percent
            $table->decimal('annual_turnover', 12, 2)->default(0);
            $this->audit($table);
            $table->unique(['tenant_id', 'country']);
        });
    }
};
