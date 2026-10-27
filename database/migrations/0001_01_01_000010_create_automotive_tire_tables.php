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
        Schema::create('vehicles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('license_plate', 20)->index(); // IT (e.g. GA123CD) or CH (e.g. TI 123456)
            $table->string('vin_chassis', 30)->nullable()->index();
            $table->string('make', 50); // Fiat, Volkswagen, etc.
            $table->string('model', 50);
            $table->unsignedSmallInteger('year')->nullable();
            $table->date('registration_date')->nullable();
            $table->unsignedInteger('mileage_km')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'license_plate']);
        });

        Schema::create('tire_hotel_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->enum('season', ['summer', 'winter', 'all_season'])->default('winter')->index();
            $table->string('brand', 50); // Michelin, Pirelli, etc.
            $table->string('model', 50)->nullable();
            $table->string('size', 30); // 205/55 R16 91V
            $table->string('dot', 10)->nullable(); // e.g. 3523
            $table->decimal('front_left_tread_mm', 4, 2);
            $table->decimal('front_right_tread_mm', 4, 2);
            $table->decimal('rear_left_tread_mm', 4, 2);
            $table->decimal('rear_right_tread_mm', 4, 2);
            $table->string('warehouse_shelf_location', 50)->nullable(); // e.g. Rack-B-04
            $table->enum('status', ['stored', 'fitted', 'disposed'])->default('stored')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'vehicle_id', 'status']);
        });

        Schema::create('vehicle_inspections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->enum('type', ['it_revisione', 'ch_mfk'])->default('it_revisione')->index();
            $table->date('scheduled_date')->index();
            $table->date('passed_date')->nullable();
            $table->string('result', 30)->default('pending')->index(); // pending, passed, failed, conditional
            $table->string('certificate_number', 50)->nullable();
            $table->text('inspector_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_inspections');
        Schema::dropIfExists('tire_hotel_records');
        Schema::dropIfExists('vehicles');
    }
};
