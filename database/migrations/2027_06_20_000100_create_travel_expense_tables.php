<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business trips on top of the expenses feature: trip requests with an approval flow, itinerary legs, per-diem and
     * mileage rate tables per company, jurisdiction (IT, CH), country and year, the calculated per-diem days, mileage
     * claims and the link between a trip and the expenses (expenses.id, checked by the application) that settle it.
     * New tables only; no business rule lives in a database constraint, so the schema is the same on every engine.
     */
    public function up(): void
    {
        if (! Schema::hasTable('travel_trips')) {
            Schema::create('travel_trips', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('number', 30);
                $table->uuid('user_id');
                $table->string('purpose', 500);
                $table->string('destination_city', 120);
                $table->string('destination_country', 2);
                $table->date('start_date');
                $table->date('end_date');
                $table->string('departure_time', 5)->nullable();
                $table->string('return_time', 5)->nullable();
                $table->string('currency', 3)->default('EUR');
                $table->string('rate_jurisdiction', 2)->default('IT');
                $table->decimal('estimated_budget', 15, 2)->default(0);
                $table->decimal('advance_amount', 15, 2)->default(0);
                $table->date('advance_paid_at')->nullable();
                $table->string('status', 20)->default('draft');
                $table->timestamp('submitted_at')->nullable();
                $table->uuid('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->uuid('rejected_by')->nullable();
                $table->timestamp('rejected_at')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('reconciled_at')->nullable();
                $table->uuid('reconciled_by')->nullable();
                $table->decimal('reconciled_expenses_total', 15, 2)->nullable();
                $table->decimal('reconciled_per_diem_total', 15, 2)->nullable();
                $table->decimal('reconciled_mileage_total', 15, 2)->nullable();
                $table->decimal('reconciled_advance_total', 15, 2)->nullable();
                $table->decimal('reconciled_balance', 15, 2)->nullable();
                $table->string('reconciled_direction', 20)->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'number']);
                $table->index(['tenant_id', 'status']);
                $table->index(['tenant_id', 'user_id']);
                $table->index(['tenant_id', 'start_date']);
            });
        }

        if (! Schema::hasTable('travel_legs')) {
            Schema::create('travel_legs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('trip_id')->constrained('travel_trips')->cascadeOnDelete();
                $table->unsignedSmallInteger('sequence')->default(1);
                $table->string('leg_type', 20);
                $table->string('from_location', 150)->nullable();
                $table->string('to_location', 150);
                $table->dateTime('departs_at')->nullable();
                $table->dateTime('arrives_at')->nullable();
                $table->string('provider', 150)->nullable();
                $table->string('booking_reference', 60)->nullable();
                $table->string('booking_status', 20)->default('planned');
                $table->decimal('booked_cost', 15, 2)->default(0);
                $table->string('currency', 3)->default('EUR');
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'trip_id']);
            });
        }

        if (! Schema::hasTable('travel_per_diem_rates')) {
            Schema::create('travel_per_diem_rates', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('jurisdiction', 2);
                $table->unsignedSmallInteger('year');
                $table->string('scope', 10);
                $table->string('country', 2)->nullable();
                $table->string('currency', 3);
                $table->decimal('daily_amount', 15, 2);
                $table->decimal('tax_free_daily_amount', 15, 2)->nullable();
                $table->decimal('lodging_deduction_percent', 7, 4)->default(0);
                $table->decimal('breakfast_deduction_percent', 7, 4)->default(0);
                $table->decimal('meal_deduction_percent', 7, 4)->default(0);
                $table->boolean('deduct_each_meal')->default(false);
                $table->unsignedSmallInteger('half_day_min_hours')->default(8);
                $table->unsignedSmallInteger('full_day_min_hours')->default(12);
                $table->string('notes', 500)->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'jurisdiction', 'year', 'scope'], 'travel_per_diem_rates_lookup');
            });
        }

        if (! Schema::hasTable('travel_mileage_rates')) {
            Schema::create('travel_mileage_rates', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('jurisdiction', 2);
                $table->unsignedSmallInteger('year');
                $table->string('vehicle_type', 20);
                $table->string('currency', 3);
                $table->decimal('rate_per_km', 10, 4);
                $table->string('notes', 500)->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'jurisdiction', 'year', 'vehicle_type'], 'travel_mileage_rates_lookup');
            });
        }

        if (! Schema::hasTable('travel_per_diem_days')) {
            Schema::create('travel_per_diem_days', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('trip_id')->constrained('travel_trips')->cascadeOnDelete();
                $table->uuid('rate_id')->nullable();
                $table->date('day');
                $table->decimal('hours_away', 5, 2)->default(0);
                $table->decimal('day_factor', 4, 2)->default(0);
                $table->boolean('breakfast_provided')->default(false);
                $table->boolean('lunch_provided')->default(false);
                $table->boolean('dinner_provided')->default(false);
                $table->boolean('lodging_provided')->default(false);
                $table->string('currency', 3);
                $table->decimal('gross_amount', 15, 2)->default(0);
                $table->decimal('deduction_amount', 15, 2)->default(0);
                $table->decimal('amount', 15, 2)->default(0);
                $table->decimal('tax_free_amount', 15, 2)->default(0);
                $table->decimal('taxable_amount', 15, 2)->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'trip_id']);
            });
        }

        if (! Schema::hasTable('travel_mileage_claims')) {
            Schema::create('travel_mileage_claims', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('trip_id')->constrained('travel_trips')->cascadeOnDelete();
                $table->date('travel_date');
                $table->string('from_location', 150);
                $table->string('to_location', 150);
                $table->string('vehicle_type', 20);
                $table->boolean('round_trip')->default(false);
                $table->decimal('distance_km', 10, 2);
                $table->decimal('rate_per_km', 10, 4);
                $table->string('currency', 3);
                $table->decimal('amount', 15, 2);
                $table->string('notes', 500)->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'trip_id']);
            });
        }

        if (! Schema::hasTable('travel_trip_expenses')) {
            Schema::create('travel_trip_expenses', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('trip_id')->constrained('travel_trips')->cascadeOnDelete();
                $table->uuid('expense_id');
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'expense_id']);
                $table->index(['tenant_id', 'trip_id']);
            });
        }
    }
};
