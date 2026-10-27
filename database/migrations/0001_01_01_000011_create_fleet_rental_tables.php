<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('rental_vehicles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->string('category', 50)->default('economy'); // economy, compact, suv, van, luxury
            $table->decimal('daily_rate', 10, 2);
            $table->string('fuel_type', 30)->default('petrol'); // petrol, diesel, hybrid, electric
            $table->string('transmission', 20)->default('manual'); // manual, automatic
            $table->boolean('is_available')->default(true)->index();
            $table->unsignedInteger('current_mileage')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('rental_bookings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('rental_vehicle_id')->constrained('rental_vehicles')->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained('contacts')->cascadeOnDelete();
            $table->dateTime('start_date');
            $table->dateTime('end_date');
            $table->string('pickup_location')->nullable();
            $table->string('return_location')->nullable();
            $table->decimal('total_estimated', 10, 2)->default(0);
            $table->decimal('deposit_amount', 10, 2)->default(0);
            $table->string('status', 30)->default('reserved')->index(); // reserved, active, completed, cancelled
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('rental_contracts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('rental_booking_id')->constrained('rental_bookings')->cascadeOnDelete();
            $table->string('contract_number', 50)->index();
            $table->longText('agreement_terms')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'contract_number']);
        });

        Schema::create('damage_inspections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('rental_booking_id')->constrained('rental_bookings')->cascadeOnDelete();
            $table->enum('type', ['check_in', 'check_out'])->index();
            $table->unsignedInteger('mileage');
            $table->string('fuel_level', 10); // 100%, 75%, 50%, etc.
            $table->json('damage_markers')->nullable(); // Diagram coordinates and scratch/dent details
            $table->json('photos')->nullable();
            $table->foreignUuid('inspected_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('inspected_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('damage_inspections');
        Schema::dropIfExists('rental_contracts');
        Schema::dropIfExists('rental_bookings');
        Schema::dropIfExists('rental_vehicles');
    }
};
