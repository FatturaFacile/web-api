<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #372 (Mod 126) — gardening and green maintenance vertical: green space sites, seasonal maintenance contracts (condominiums
 * included), crew teams with their phytosanitary operator licenses, weekly route schedules with the stops of the crews, the
 * phytosanitary treatment diary (Quaderno di Campagna / Registro dei trattamenti) and the green waste slips (CER / OTR).
 * Dialect-agnostic (SQLite and PostgreSQL): JSON documents are stored as text columns through Eloquent array casts.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->sites();
        $this->contracts();
        $this->crews();
        $this->licenses();
        $this->routes();
        $this->stops();
        $this->treatments();
        $this->wasteSlips();
    }

    public function down(): void
    {
        foreach ([
            'green_waste_slips', 'phytosanitary_treatment_logs', 'crew_route_stops', 'crew_route_schedules',
            'operator_phytosanitary_licenses', 'crew_team_members', 'crew_teams', 'seasonal_maintenance_contracts', 'green_space_sites',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function sites(): void
    {
        if (Schema::hasTable('green_space_sites')) {
            return;
        }

        Schema::create('green_space_sites', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignUuid('condominium_administrator_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('code', 40);
            $table->string('name', 190);
            $table->string('jurisdiction', 2)->default('IT');
            $table->string('address', 255)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('geo_polygon_coordinates')->nullable();
            $table->decimal('lawn_area_sqm', 12, 2)->default(0);
            $table->decimal('hedge_length_meters', 12, 2)->default(0);
            $table->unsignedInteger('tree_count')->default(0);
            $table->text('irrigation_system_specs')->nullable();
            $table->text('access_gate_code')->nullable();
            $table->text('access_notes')->nullable();
            $table->text('botanical_inventory')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'customer_id']);
        });
    }

    private function contracts(): void
    {
        if (Schema::hasTable('seasonal_maintenance_contracts')) {
            return;
        }

        Schema::create('seasonal_maintenance_contracts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('contract_number', 40);
            $table->foreignUuid('site_id')->constrained('green_space_sites')->restrictOnDelete();
            $table->foreignUuid('customer_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignUuid('condominium_administrator_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->date('season_start_date');
            $table->date('season_end_date');
            $table->string('jurisdiction', 2)->default('IT');
            $table->string('billing_mode', 30)->default('fixed_installments');
            $table->unsignedInteger('included_passages_count')->default(0);
            $table->unsignedInteger('completed_passages_count')->default(0);
            $table->decimal('extra_passage_unit_price', 12, 2)->default(0);
            $table->decimal('fixed_fee_amount', 12, 2)->default(0);
            $table->string('installment_frequency', 30)->default('monthly');
            $table->unsignedInteger('installments_billed')->default(0);
            $table->decimal('time_and_material_hourly_rate', 12, 2)->default(0);
            $table->decimal('snow_removal_standby_fee', 12, 2)->nullable();
            $table->decimal('snow_trigger_threshold_cm', 8, 2)->nullable();
            $table->boolean('snow_standby_billed')->default(false);
            $table->decimal('vat_rate', 5, 2)->default(22);
            $table->text('included_services')->nullable();
            $table->boolean('auto_bill_extra_passages')->default(true);
            $table->boolean('apply_condominium_withholding')->default(false);
            $table->boolean('is_condominium')->default(false);
            $table->string('condominium_fiscal_code', 20)->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'contract_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'site_id']);
        });
    }

    private function crews(): void
    {
        if (! Schema::hasTable('crew_teams')) {
            Schema::create('crew_teams', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('code', 40);
                $table->string('name', 190);
                $table->string('default_vehicle_plate', 20)->nullable();
                $table->string('default_trailer_plate', 20)->nullable();
                $table->text('equipment')->nullable();
                $table->unsignedInteger('daily_capacity_minutes')->default(480);
                $table->decimal('depot_latitude', 10, 7)->nullable();
                $table->decimal('depot_longitude', 10, 7)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['tenant_id', 'code']);
            });
        }

        if (! Schema::hasTable('crew_team_members')) {
            Schema::create('crew_team_members', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('crew_team_id')->constrained('crew_teams')->cascadeOnDelete();
                $table->uuid('user_id')->nullable();
                $table->string('name', 190);
                $table->string('role', 60)->nullable();
                $table->decimal('hourly_rate', 10, 2)->default(0);
                $table->timestamps();
                $table->softDeletes();
                $table->index(['tenant_id', 'crew_team_id']);
            });
        }
    }

    private function licenses(): void
    {
        if (Schema::hasTable('operator_phytosanitary_licenses')) {
            return;
        }

        Schema::create('operator_phytosanitary_licenses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('crew_team_member_id')->constrained('crew_team_members')->cascadeOnDelete();
            $table->string('license_type', 10);
            $table->string('jurisdiction', 2);
            $table->string('license_number', 80);
            $table->string('issuing_authority', 190)->nullable();
            $table->date('issue_date');
            $table->date('expiry_date');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'crew_team_member_id']);
        });
    }

    private function routes(): void
    {
        if (Schema::hasTable('crew_route_schedules')) {
            return;
        }

        Schema::create('crew_route_schedules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->date('schedule_date');
            $table->foreignUuid('crew_team_id')->constrained('crew_teams')->restrictOnDelete();
            $table->string('assigned_vehicle_plate', 20)->nullable();
            $table->string('assigned_trailer_plate', 20)->nullable();
            $table->text('equipment_list')->nullable();
            $table->string('weather_alert_status', 40)->default('clear');
            $table->text('weather_forecast_summary')->nullable();
            $table->text('route_optimization_metadata')->nullable();
            $table->string('status', 20)->default('planned');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'schedule_date']);
            $table->index(['tenant_id', 'crew_team_id', 'schedule_date']);
        });
    }

    private function stops(): void
    {
        if (Schema::hasTable('crew_route_stops')) {
            return;
        }

        Schema::create('crew_route_stops', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('route_schedule_id')->constrained('crew_route_schedules')->cascadeOnDelete();
            $table->foreignUuid('site_id')->constrained('green_space_sites')->restrictOnDelete();
            $table->foreignUuid('contract_id')->nullable()->constrained('seasonal_maintenance_contracts')->nullOnDelete();
            $table->unsignedInteger('stop_order')->default(1);
            $table->text('planned_activities')->nullable();
            $table->unsignedInteger('estimated_duration_minutes')->default(60);
            $table->dateTime('actual_checkin_at')->nullable();
            $table->dateTime('actual_checkout_at')->nullable();
            $table->text('checkin_gps')->nullable();
            $table->text('checkout_gps')->nullable();
            $table->text('crew_hours_logged')->nullable();
            $table->text('machinery_hours_logged')->nullable();
            $table->text('materials_and_plants_used')->nullable();
            $table->text('before_photos')->nullable();
            $table->text('after_photos')->nullable();
            $table->boolean('counts_toward_contract_passage')->default(true);
            $table->boolean('is_extra_passage')->default(false);
            $table->unsignedInteger('passage_number')->nullable();
            $table->decimal('extra_passage_amount', 12, 2)->default(0);
            $table->string('billing_status', 30)->default('not_billed');
            $table->uuid('invoice_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'route_schedule_id']);
            $table->index(['tenant_id', 'contract_id', 'billing_status']);
        });
    }

    private function treatments(): void
    {
        if (Schema::hasTable('phytosanitary_treatment_logs')) {
            return;
        }

        Schema::create('phytosanitary_treatment_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('site_id')->constrained('green_space_sites')->restrictOnDelete();
            $table->foreignUuid('route_stop_id')->nullable()->constrained('crew_route_stops')->nullOnDelete();
            $table->date('treatment_date');
            $table->string('jurisdiction', 2)->default('IT');
            $table->string('target_pest_or_weed', 190);
            $table->string('treated_crop_or_surface', 190);
            $table->decimal('treated_area_sqm', 12, 2)->default(0);
            $table->string('commercial_product_name', 190);
            $table->string('registration_number', 60);
            $table->string('active_ingredient', 190);
            $table->decimal('dosage_used', 12, 4);
            $table->string('dosage_unit', 20);
            $table->decimal('water_volume_liters', 12, 2)->nullable();
            $table->uuid('warehouse_product_id')->nullable();
            $table->uuid('warehouse_id')->nullable();
            $table->decimal('quantity_deducted', 14, 4)->default(0);
            $table->foreignUuid('operator_id')->constrained('crew_team_members')->restrictOnDelete();
            $table->foreignUuid('operator_license_id')->constrained('operator_phytosanitary_licenses')->restrictOnDelete();
            $table->unsignedInteger('re_entry_interval_hours')->default(0);
            $table->text('weather_conditions_at_application')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            // The diary is immutable: the model refuses updates and deletes; the column only satisfies the shared soft-delete scope.
            $table->softDeletes();
            $table->index(['tenant_id', 'treatment_date']);
            $table->index(['tenant_id', 'site_id']);
            $table->index(['tenant_id', 'operator_id']);
        });
    }

    private function wasteSlips(): void
    {
        if (Schema::hasTable('green_waste_slips')) {
            return;
        }

        Schema::create('green_waste_slips', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('slip_number', 60);
            $table->foreignUuid('site_id')->nullable()->constrained('green_space_sites')->nullOnDelete();
            $table->foreignUuid('route_schedule_id')->nullable()->constrained('crew_route_schedules')->nullOnDelete();
            $table->foreignUuid('route_stop_id')->nullable()->constrained('crew_route_stops')->nullOnDelete();
            $table->date('disposal_date');
            $table->string('waste_code', 30);
            $table->string('waste_description', 255)->nullable();
            $table->decimal('green_waste_weight_kg', 12, 2);
            $table->foreignUuid('compost_facility_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('transporter_vehicle_plate', 20)->nullable();
            $table->string('document_reference', 80)->nullable();
            $table->decimal('disposal_cost', 12, 2)->default(0);
            $table->boolean('is_billable_to_customer')->default(false);
            $table->uuid('invoice_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'slip_number']);
            $table->index(['tenant_id', 'disposal_date']);
        });
    }
};
