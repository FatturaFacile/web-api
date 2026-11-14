<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fleet management (company vehicles): vehicles with their odometer, driver assignments, fuel and charging entries,
     * service records and scheduled services, compliance deadlines and incidents or fines. New tables only; business
     * rules (odometer never decreases, no overlapping assignments) are enforced by the application, not by the engine.
     */
    public function up(): void
    {
        if (! Schema::hasTable('fleet_vehicles')) {
            Schema::create('fleet_vehicles', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('license_plate', 20);
                $table->string('vin', 32)->nullable();
                $table->string('make', 80);
                $table->string('model', 80);
                $table->unsignedSmallInteger('model_year')->nullable();
                $table->string('vehicle_type', 20)->default('car');
                $table->string('fuel_type', 20)->default('diesel');
                $table->string('status', 20)->default('active');
                $table->unsignedBigInteger('initial_odometer_km')->default(0);
                $table->unsignedBigInteger('odometer_km')->default(0);
                $table->string('ownership', 20)->default('owned');
                $table->date('acquired_on')->nullable();
                $table->date('contract_ends_on')->nullable();
                $table->decimal('purchase_value', 14, 2)->nullable();
                $table->string('currency', 3)->default('EUR');
                $table->uuid('asset_id')->nullable()->index();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'license_plate']);
                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('fleet_vehicle_assignments')) {
            Schema::create('fleet_vehicle_assignments', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('vehicle_id')->constrained('fleet_vehicles')->cascadeOnDelete();
                $table->uuid('employee_id')->nullable()->index();
                $table->string('driver_label', 120)->nullable();
                $table->date('starts_on');
                $table->date('ends_on')->nullable();
                $table->unsignedBigInteger('start_odometer_km');
                $table->unsignedBigInteger('end_odometer_km')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'vehicle_id', 'starts_on']);
            });
        }

        if (! Schema::hasTable('fleet_scheduled_services')) {
            Schema::create('fleet_scheduled_services', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('vehicle_id')->constrained('fleet_vehicles')->cascadeOnDelete();
                $table->string('name', 160);
                $table->string('service_type', 20)->default('maintenance');
                $table->unsignedInteger('interval_days')->nullable();
                $table->unsignedInteger('interval_km')->nullable();
                $table->date('last_service_on')->nullable();
                $table->unsignedBigInteger('last_service_km')->nullable();
                $table->date('next_due_on')->nullable();
                $table->unsignedBigInteger('next_due_km')->nullable();
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'vehicle_id']);
                $table->index(['tenant_id', 'next_due_on']);
            });
        }

        if (! Schema::hasTable('fleet_service_records')) {
            Schema::create('fleet_service_records', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('vehicle_id')->constrained('fleet_vehicles')->cascadeOnDelete();
                $table->uuid('scheduled_service_id')->nullable()->index();
                $table->string('service_type', 20)->default('maintenance');
                $table->date('service_date');
                $table->unsignedBigInteger('odometer_km');
                $table->string('vendor', 160)->nullable();
                $table->string('description', 255);
                $table->string('reference', 80)->nullable();
                $table->decimal('cost', 14, 2)->default(0);
                $table->string('currency', 3)->default('EUR');
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'vehicle_id', 'service_date']);
            });
        }

        if (! Schema::hasTable('fleet_fuel_entries')) {
            Schema::create('fleet_fuel_entries', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('vehicle_id')->constrained('fleet_vehicles')->cascadeOnDelete();
                $table->uuid('employee_id')->nullable()->index();
                $table->date('entry_date');
                $table->string('energy_unit', 10)->default('liter');
                $table->decimal('quantity', 12, 3);
                $table->decimal('unit_price', 12, 4)->nullable();
                $table->decimal('total_cost', 14, 2);
                $table->string('currency', 3)->default('EUR');
                $table->unsignedBigInteger('odometer_km');
                $table->boolean('is_full_tank')->default(true);
                $table->string('station', 160)->nullable();
                $table->unsignedBigInteger('distance_km')->nullable();
                $table->decimal('consumption_per_100km', 12, 3)->nullable();
                $table->decimal('cost_per_km', 12, 4)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'vehicle_id', 'entry_date']);
            });
        }

        if (! Schema::hasTable('fleet_deadlines')) {
            Schema::create('fleet_deadlines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('vehicle_id')->constrained('fleet_vehicles')->cascadeOnDelete();
                $table->string('deadline_type', 20);
                $table->string('provider', 160)->nullable();
                $table->string('reference', 80)->nullable();
                $table->date('expires_on');
                $table->decimal('cost', 14, 2)->default(0);
                $table->string('currency', 3)->default('EUR');
                $table->string('status', 20)->default('open');
                $table->timestamp('renewed_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'vehicle_id']);
                $table->index(['tenant_id', 'status', 'expires_on']);
            });
        }

        if (! Schema::hasTable('fleet_incidents')) {
            Schema::create('fleet_incidents', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('vehicle_id')->constrained('fleet_vehicles')->cascadeOnDelete();
                $table->uuid('employee_id')->nullable()->index();
                $table->string('kind', 20)->default('fine');
                $table->date('occurred_on');
                $table->string('reference', 80)->nullable();
                $table->string('description', 255);
                $table->string('location', 160)->nullable();
                $table->decimal('amount', 14, 2)->default(0);
                $table->string('currency', 3)->default('EUR');
                $table->string('payment_status', 20)->default('unpaid');
                $table->date('due_on')->nullable();
                $table->date('paid_on')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'vehicle_id', 'occurred_on']);
                $table->index(['tenant_id', 'payment_status']);
            });
        }
    }

    public function down(): void
    {
        foreach (['fleet_incidents', 'fleet_deadlines', 'fleet_fuel_entries', 'fleet_service_records', 'fleet_scheduled_services', 'fleet_vehicle_assignments', 'fleet_vehicles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
