<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Transportation management (TMS): carriers with tariff tables and bands, transport orders, trips with stops, freight quotes, tracking events and carrier invoices with their audit lines. New tables only; amounts are exact decimals and the business rules (rate bands never overlap, order and trip states, invoice audit) are enforced by the application, not by the engine.
     */
    public function up(): void
    {
        if (! Schema::hasTable('tms_carriers')) {
            Schema::create('tms_carriers', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('code', 30);
                $table->string('name', 160);
                $table->string('mode', 30)->default('road');
                $table->string('service_level', 60)->nullable();
                $table->string('currency', 3)->default('EUR');
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'code']);
            });
        }

        if (! Schema::hasTable('tms_tariff_tables')) {
            Schema::create('tms_tariff_tables', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('carrier_id')->constrained('tms_carriers')->cascadeOnDelete();
                $table->string('name', 160);
                $table->string('currency', 3)->default('EUR');
                $table->date('valid_from');
                $table->date('valid_to')->nullable();
                $table->string('rate_basis', 30)->default('weight');
                $table->decimal('fuel_surcharge_percent', 6, 2)->default('0');
                $table->decimal('minimum_charge', 14, 2)->default('0');
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'carrier_id', 'valid_from']);
            });
        }

        if (! Schema::hasTable('tms_tariff_bands')) {
            Schema::create('tms_tariff_bands', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('tariff_table_id')->constrained('tms_tariff_tables')->cascadeOnDelete();
                $table->string('origin_zone', 30)->nullable();
                $table->string('destination_zone', 30)->nullable();
                $table->decimal('weight_from_kg', 12, 3)->default('0');
                $table->decimal('weight_to_kg', 12, 3)->nullable();
                $table->decimal('distance_from_km', 10, 2)->default('0');
                $table->decimal('distance_to_km', 10, 2)->nullable();
                $table->decimal('fixed_amount', 14, 2)->default('0');
                $table->decimal('price_per_kg', 14, 4)->default('0');
                $table->decimal('price_per_km', 14, 4)->default('0');
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'tariff_table_id']);
            });
        }

        if (! Schema::hasTable('tms_trips')) {
            Schema::create('tms_trips', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('number', 40)->index();
                $table->string('status', 30)->default('planned')->index();
                $table->foreignUuid('carrier_id')->nullable()->constrained('tms_carriers')->nullOnDelete();
                $table->foreignUuid('vehicle_id')->nullable()->constrained('fleet_vehicles')->nullOnDelete();
                $table->dateTime('planned_start_at')->nullable();
                $table->dateTime('planned_end_at')->nullable();
                $table->dateTime('actual_start_at')->nullable();
                $table->dateTime('actual_end_at')->nullable();
                $table->decimal('capacity_weight_kg', 12, 3)->nullable();
                $table->decimal('capacity_volume_cbm', 12, 3)->nullable();
                $table->decimal('planned_distance_km', 10, 2)->nullable();
                $table->decimal('actual_distance_km', 10, 2)->nullable();
                $table->decimal('actual_cost', 14, 2)->nullable();
                $table->string('currency', 3)->default('EUR');
                $table->decimal('total_weight_kg', 12, 3)->default('0');
                $table->decimal('total_volume_cbm', 12, 3)->default('0');
                $table->decimal('utilization_percent', 6, 2)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'status', 'planned_start_at']);
            });
        }

        if (! Schema::hasTable('tms_transport_orders')) {
            Schema::create('tms_transport_orders', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('number', 40)->index();
                $table->string('reference', 100)->nullable();
                $table->string('status', 30)->default('draft')->index();
                $table->string('origin_name', 160);
                $table->string('origin_location', 255);
                $table->string('origin_city', 100)->nullable();
                $table->string('origin_zone', 30)->nullable();
                $table->string('destination_name', 160);
                $table->string('destination_location', 255);
                $table->string('destination_city', 100)->nullable();
                $table->string('destination_zone', 30)->nullable();
                $table->dateTime('pickup_from')->nullable();
                $table->dateTime('pickup_to')->nullable();
                $table->dateTime('delivery_from')->nullable();
                $table->dateTime('delivery_to')->nullable();
                $table->decimal('weight_kg', 12, 3);
                $table->decimal('volume_cbm', 12, 3)->default('0');
                $table->integer('pallets')->default(0);
                $table->decimal('distance_km', 10, 2)->nullable();
                $table->string('goods_description', 255)->nullable();
                $table->foreignUuid('carrier_id')->nullable()->constrained('tms_carriers')->nullOnDelete();
                $table->string('currency', 3)->default('EUR');
                $table->decimal('expected_cost', 14, 2)->nullable();
                $table->decimal('actual_cost', 14, 2)->nullable();
                $table->foreignUuid('trip_id')->nullable()->constrained('tms_trips')->nullOnDelete();
                $table->dateTime('delivered_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'status']);
                $table->index(['tenant_id', 'delivered_at']);
            });
        }

        if (! Schema::hasTable('tms_trip_stops')) {
            Schema::create('tms_trip_stops', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('trip_id')->constrained('tms_trips')->cascadeOnDelete();
                $table->integer('sequence');
                $table->string('stop_type', 30);
                $table->foreignUuid('transport_order_id')->nullable()->constrained('tms_transport_orders')->nullOnDelete();
                $table->string('location', 255);
                $table->string('city', 100)->nullable();
                $table->dateTime('planned_arrival_at')->nullable();
                $table->dateTime('actual_arrival_at')->nullable();
                $table->dateTime('actual_departure_at')->nullable();
                $table->string('status', 30)->default('pending');
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'trip_id', 'sequence']);
            });
        }

        if (! Schema::hasTable('tms_quotes')) {
            Schema::create('tms_quotes', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('reference', 100)->nullable();
                $table->foreignUuid('transport_order_id')->nullable()->constrained('tms_transport_orders')->nullOnDelete();
                $table->date('ship_date');
                $table->decimal('weight_kg', 12, 3);
                $table->decimal('distance_km', 10, 2)->nullable();
                $table->string('origin_zone', 30)->nullable();
                $table->string('destination_zone', 30)->nullable();
                $table->string('currency', 3)->default('EUR');
                $table->string('status', 30)->default('open')->index();
                $table->json('offers')->nullable();
                $table->foreignUuid('awarded_carrier_id')->nullable()->constrained('tms_carriers')->nullOnDelete();
                $table->decimal('awarded_amount', 14, 2)->nullable();
                $table->dateTime('awarded_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('tms_tracking_events')) {
            Schema::create('tms_tracking_events', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('transport_order_id')->constrained('tms_transport_orders')->cascadeOnDelete();
                $table->string('event_type', 30);
                $table->string('location', 160)->nullable();
                $table->dateTime('occurred_at')->index();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'transport_order_id', 'occurred_at']);
            });
        }

        if (! Schema::hasTable('tms_freight_invoices')) {
            Schema::create('tms_freight_invoices', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('carrier_id')->constrained('tms_carriers')->cascadeOnDelete();
                $table->string('invoice_number', 60);
                $table->date('invoice_date');
                $table->string('currency', 3)->default('EUR');
                $table->decimal('total_amount', 14, 2)->default('0');
                $table->decimal('expected_total', 14, 2)->default('0');
                $table->decimal('variance_amount', 14, 2)->default('0');
                $table->decimal('tolerance_percent', 5, 2)->default('2');
                $table->string('audit_status', 30)->default('pending')->index();
                $table->string('dispute_status', 30)->default('none')->index();
                $table->text('dispute_reason')->nullable();
                $table->text('resolution_note')->nullable();
                $table->decimal('credited_amount', 14, 2)->nullable();
                $table->dateTime('audited_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'carrier_id', 'invoice_number']);
            });
        }

        if (! Schema::hasTable('tms_freight_invoice_lines')) {
            Schema::create('tms_freight_invoice_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('freight_invoice_id')->constrained('tms_freight_invoices')->cascadeOnDelete();
                $table->foreignUuid('transport_order_id')->nullable()->constrained('tms_transport_orders')->nullOnDelete();
                $table->string('description', 255)->nullable();
                $table->decimal('billed_amount', 14, 2);
                $table->decimal('expected_amount', 14, 2)->nullable();
                $table->decimal('variance_amount', 14, 2)->nullable();
                $table->decimal('variance_percent', 8, 2)->nullable();
                $table->string('audit_status', 30)->default('pending');
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'freight_invoice_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tms_freight_invoice_lines');
        Schema::dropIfExists('tms_freight_invoices');
        Schema::dropIfExists('tms_tracking_events');
        Schema::dropIfExists('tms_quotes');
        Schema::dropIfExists('tms_trip_stops');
        Schema::dropIfExists('tms_transport_orders');
        Schema::dropIfExists('tms_trips');
        Schema::dropIfExists('tms_tariff_bands');
        Schema::dropIfExists('tms_tariff_tables');
        Schema::dropIfExists('tms_carriers');
    }
};
