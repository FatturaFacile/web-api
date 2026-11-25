<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mod 176 — passenger transport vertical (NCC, taxi, GT coach, school bus): bookings with flight tracking and cross-border
 * kilometre split, fleet, driver compliance and tachograph (.DDD) archive, NCC electronic service sheets, hotel concierge and
 * municipal school bus contracts.
 * Dialect-agnostic: runs identically on SQLite and PostgreSQL. Every table carries the tenant, soft deletes and the audit columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->fleetVehicles();
        $this->driverComplianceStates();
        $this->dddArchives();
        $this->driverTachographEvents();
        $this->hotelContracts();
        $this->schoolBusContracts();
        $this->schoolBusBillings();
        $this->bookings();
        $this->serviceSheets();
    }

    public function down(): void
    {
        foreach (['ptr_service_sheets', 'ptr_bookings', 'ptr_school_bus_billings', 'ptr_school_bus_contracts', 'ptr_hotel_concierge_contracts', 'ptr_driver_tachograph_events', 'ptr_ddd_archives', 'ptr_driver_compliance_states', 'ptr_fleet_vehicles'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function base(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
    }

    private function audit(Blueprint $table): void
    {
        $table->uuid('created_by')->nullable();
        $table->uuid('updated_by')->nullable();
        $table->uuid('deleted_by')->nullable();
        $table->timestamps();
        $table->softDeletes();
    }

    private function fleetVehicles(): void
    {
        if (Schema::hasTable('ptr_fleet_vehicles')) {
            return;
        }

        Schema::create('ptr_fleet_vehicles', function (Blueprint $table): void {
            $this->base($table);
            $table->string('license_plate', 20);
            $table->string('vehicle_category', 30);
            $table->decimal('gross_weight_tons', 6, 2)->default(0);
            $table->unsignedSmallInteger('passenger_capacity')->default(4);
            $table->boolean('has_digital_tachograph')->default(false);
            $table->boolean('psva_subject')->default(false);
            $table->string('ncc_authorization_number', 60)->nullable();
            $table->string('ncc_issuing_municipality', 120)->nullable();
            $table->unsignedInteger('odometer_km')->default(0);
            $table->boolean('active')->default(true);
            $this->audit($table);
            $table->unique(['tenant_id', 'license_plate']);
        });
    }

    private function driverComplianceStates(): void
    {
        if (Schema::hasTable('ptr_driver_compliance_states')) {
            return;
        }

        Schema::create('ptr_driver_compliance_states', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('driver_employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('driving_license_number', 40)->nullable();
            $table->json('driving_license_categories')->nullable();
            $table->date('driving_license_expiry_date')->nullable();
            $table->string('cqc_persone_number_it', 40)->nullable();
            $table->date('cqc_persone_expiry_date')->nullable();
            $table->string('czv_certificate_number_ch', 40)->nullable();
            $table->date('czv_expiry_date')->nullable();
            $table->string('tachograph_card_number', 20)->nullable();
            $table->date('tachograph_card_expiry_date')->nullable();
            $table->unsignedInteger('daily_driving_minutes_used')->default(0);
            $table->unsignedSmallInteger('extended_10h_days_used_this_week')->default(0);
            $table->unsignedInteger('continuous_driving_minutes_since_break')->default(0);
            $table->unsignedInteger('weekly_driving_minutes_used')->default(0);
            $table->unsignedInteger('biweekly_driving_minutes_used')->default(0);
            $table->unsignedInteger('last_daily_rest_duration_minutes')->default(660);
            $table->unsignedSmallInteger('reduced_9h_rests_used_between_weekly_rests')->default(0);
            $table->dateTime('last_weekly_rest_ended_at')->nullable();
            $table->boolean('available_for_next_trip')->default(true);
            $table->dateTime('next_mandatory_break_or_rest_at')->nullable();
            $table->dateTime('counters_updated_at')->nullable();
            $this->audit($table);
            $table->unique(['tenant_id', 'driver_employee_id']);
        });
    }

    private function dddArchives(): void
    {
        if (Schema::hasTable('ptr_ddd_archives')) {
            return;
        }

        Schema::create('ptr_ddd_archives', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('driver_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->foreignUuid('vehicle_id')->nullable()->constrained('ptr_fleet_vehicles')->nullOnDelete();
            $table->string('file_type', 20);
            $table->string('original_name', 255);
            $table->string('storage_path', 500);
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('sha256', 64);
            $table->string('card_number', 20)->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->unsignedInteger('infringements_count')->default(0);
            $table->json('infringements_summary')->nullable();
            $this->audit($table);
            $table->unique(['tenant_id', 'sha256']);
            $table->index(['tenant_id', 'driver_employee_id']);
        });
    }

    private function driverTachographEvents(): void
    {
        if (Schema::hasTable('ptr_driver_tachograph_events')) {
            return;
        }

        Schema::create('ptr_driver_tachograph_events', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('driver_employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignUuid('ddd_archive_id')->nullable()->constrained('ptr_ddd_archives')->cascadeOnDelete();
            $table->string('event_type', 20);
            $table->date('event_date');
            $table->unsignedInteger('driving_minutes')->default(0);
            $table->unsignedInteger('work_minutes')->default(0);
            $table->unsignedInteger('longest_rest_minutes')->default(0);
            $table->string('infringement_code', 60)->nullable();
            $table->unsignedInteger('infringement_minutes')->default(0);
            $this->audit($table);
            $table->index(['tenant_id', 'driver_employee_id', 'event_date']);
        });
    }

    private function hotelContracts(): void
    {
        if (Schema::hasTable('ptr_hotel_concierge_contracts')) {
            return;
        }

        Schema::create('ptr_hotel_concierge_contracts', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 160);
            $table->decimal('commission_rate', 6, 3)->default(0);
            $table->string('currency', 3)->default('CHF');
            $table->json('rate_card')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->string('billing_cycle', 20)->default('monthly');
            $table->boolean('is_active')->default(true);
            $this->audit($table);
            $table->unique(['tenant_id', 'code']);
        });
    }

    private function schoolBusContracts(): void
    {
        if (Schema::hasTable('ptr_school_bus_contracts')) {
            return;
        }

        Schema::create('ptr_school_bus_contracts', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 160);
            $table->string('currency', 3)->default('EUR');
            $table->json('rate_card')->nullable();
            $table->decimal('monthly_flat_amount', 14, 2)->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $this->audit($table);
            $table->unique(['tenant_id', 'code']);
        });
    }

    /**
     * The months whose flat amount of a school bus contract is already invoiced: one row per contract and month, so the month-end run is safe to repeat.
     */
    private function schoolBusBillings(): void
    {
        if (Schema::hasTable('ptr_school_bus_billings')) {
            return;
        }

        Schema::create('ptr_school_bus_billings', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('school_contract_id')->constrained('ptr_school_bus_contracts')->cascadeOnDelete();
            $table->string('period', 7);
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $this->audit($table);
            $table->unique(['school_contract_id', 'period']);
        });
    }

    private function bookings(): void
    {
        if (Schema::hasTable('ptr_bookings')) {
            return;
        }

        Schema::create('ptr_bookings', function (Blueprint $table): void {
            $this->base($table);
            $table->string('booking_code', 30);
            $table->string('service_type', 30);
            $table->string('status', 30)->default('draft');
            $table->foreignUuid('ordering_customer_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->uuid('b2b_concierge_user_id')->nullable();
            $table->foreignUuid('hotel_contract_id')->nullable()->constrained('ptr_hotel_concierge_contracts')->nullOnDelete();
            $table->foreignUuid('school_contract_id')->nullable()->constrained('ptr_school_bus_contracts')->nullOnDelete();
            $table->string('lead_passenger_name', 160)->nullable();
            $table->string('lead_passenger_phone', 40)->nullable();
            $table->string('vip_welcome_sign_text', 200)->nullable();
            $table->string('vip_welcome_logo_path', 500)->nullable();
            $table->unsignedSmallInteger('passengers_count')->default(1);
            $table->unsignedSmallInteger('luggage_count')->default(0);
            $table->json('child_seats_spec')->nullable();
            $table->text('accessibility_notes')->nullable();
            $table->string('flight_or_train_number', 20)->nullable();
            $table->string('flight_carrier_iata', 3)->nullable();
            $table->string('arrival_airport_iata', 3)->nullable();
            $table->dateTime('scheduled_flight_arrival_at')->nullable();
            $table->dateTime('estimated_flight_arrival_at')->nullable();
            $table->string('flight_status', 20)->nullable();
            $table->integer('flight_delay_minutes')->default(0);
            $table->dateTime('flight_checked_at')->nullable();
            $table->dateTime('pickup_datetime');
            $table->dateTime('estimated_dropoff_datetime')->nullable();
            $table->string('pickup_address', 255);
            $table->decimal('pickup_lat', 10, 7)->nullable();
            $table->decimal('pickup_lng', 10, 7)->nullable();
            $table->string('pickup_country_code', 2)->default('IT');
            $table->string('dropoff_address', 255);
            $table->decimal('dropoff_lat', 10, 7)->nullable();
            $table->decimal('dropoff_lng', 10, 7)->nullable();
            $table->string('dropoff_country_code', 2)->default('IT');
            $table->json('waypoints')->nullable();
            $table->decimal('total_distance_km', 10, 2)->default(0);
            $table->decimal('it_territory_km', 10, 2)->default(0);
            $table->decimal('ch_territory_km', 10, 2)->default(0);
            $table->decimal('other_eu_territory_km', 10, 2)->default(0);
            $table->string('border_crossing_point', 30)->default('none');
            $table->decimal('agreed_price_net', 14, 2)->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->boolean('vat_exemption_art23')->default(false);
            $table->boolean('municipal_urban_service')->default(false);
            $table->decimal('hotel_concierge_commission_rate', 6, 3)->nullable();
            $table->decimal('hotel_concierge_commission_amount', 14, 2)->default(0);
            $table->string('exchange_direction', 12)->default('none');
            $table->foreignUuid('subcontracted_carrier_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->decimal('subcontractor_payout_amount', 14, 2)->default(0);
            $table->decimal('assignor_commission_amount', 14, 2)->default(0);
            $table->foreignUuid('assigned_vehicle_id')->nullable()->constrained('ptr_fleet_vehicles')->nullOnDelete();
            $table->foreignUuid('assigned_driver_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $this->audit($table);
            $table->unique(['tenant_id', 'booking_code']);
            $table->index(['tenant_id', 'pickup_datetime']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'assigned_driver_id']);
            $table->index(['tenant_id', 'assigned_vehicle_id']);
        });
    }

    private function serviceSheets(): void
    {
        if (Schema::hasTable('ptr_service_sheets')) {
            return;
        }

        Schema::create('ptr_service_sheets', function (Blueprint $table): void {
            $this->base($table);
            $table->unsignedSmallInteger('sheet_year');
            $table->unsignedInteger('progressive_sheet_number');
            $table->foreignUuid('booking_id')->constrained('ptr_bookings')->cascadeOnDelete();
            $table->foreignUuid('vehicle_id')->nullable()->constrained('ptr_fleet_vehicles')->nullOnDelete();
            $table->foreignUuid('driver_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->string('status', 20)->default('open');
            $table->dateTime('garage_departure_at')->nullable();
            $table->unsignedInteger('garage_departure_km')->nullable();
            $table->dateTime('service_start_at')->nullable();
            $table->unsignedInteger('service_start_km')->nullable();
            $table->dateTime('service_end_at')->nullable();
            $table->unsignedInteger('service_end_km')->nullable();
            $table->dateTime('garage_return_at')->nullable();
            $table->unsignedInteger('garage_return_km')->nullable();
            $table->string('mit_fdse_protocol_code_it', 60)->nullable();
            $table->dateTime('mit_fdse_transmitted_at')->nullable();
            $table->string('interbus_eu_waybill_number', 60)->nullable();
            $table->json('passenger_manifest')->nullable();
            $table->string('digital_signature_hash', 64)->nullable();
            $table->string('pdf_storage_path', 500)->nullable();
            $this->audit($table);
            $table->unique(['tenant_id', 'sheet_year', 'progressive_sheet_number'], 'ptr_service_sheets_progressive_unique');
            $table->index(['tenant_id', 'booking_id']);
        });
    }
};
