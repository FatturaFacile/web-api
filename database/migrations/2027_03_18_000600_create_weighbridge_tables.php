<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vehicle cards with their stored tare, weighing tickets with first and second weight, values read from weighbridge
     * devices, and the unit a delivery line is billed in.
     */
    public function up(): void
    {
        Schema::create('weighbridge_vehicles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('license_plate', 30);
            $table->string('card_number', 50)->nullable();
            $table->uuid('contact_id')->nullable()->index();
            $table->decimal('stored_tare_kg', 12, 3)->nullable();
            $table->date('tare_recorded_on')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('contact_id')->references('id')->on('contacts')->nullOnDelete();
            $table->unique(['tenant_id', 'license_plate'], 'uq_weighbridge_vehicle_plate');
        });

        Schema::create('weighbridge_readings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('device_code', 50);
            $table->decimal('weight_kg', 12, 3);
            $table->boolean('is_stable')->default(true);
            $table->timestamp('read_at');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'device_code', 'read_at'], 'idx_weighbridge_reading_device');
        });

        Schema::create('weighbridge_tickets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('ticket_number', 50);
            $table->uuid('weighbridge_vehicle_id')->index();
            $table->uuid('contact_id')->nullable()->index();
            $table->uuid('stock_item_id')->nullable()->index();
            $table->string('direction', 10)->default('outbound');
            $table->string('status', 20)->default('open')->index();
            $table->decimal('first_weight_kg', 12, 3);
            $table->timestamp('first_weighed_at');
            $table->decimal('second_weight_kg', 12, 3)->nullable();
            $table->timestamp('second_weighed_at')->nullable();
            $table->boolean('used_stored_tare')->default(false);
            $table->decimal('gross_kg', 12, 3)->nullable();
            $table->decimal('tare_kg', 12, 3)->nullable();
            $table->decimal('net_kg', 12, 3)->nullable();
            $table->uuid('delivery_note_item_id')->nullable()->index();
            $table->string('billing_uom', 5)->nullable();
            $table->decimal('density_kg_m3', 12, 3)->nullable();
            $table->decimal('billed_quantity', 14, 4)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('weighbridge_vehicle_id')->references('id')->on('weighbridge_vehicles')->restrictOnDelete();
            $table->foreign('delivery_note_item_id')->references('id')->on('delivery_note_items')->nullOnDelete();
            $table->unique(['tenant_id', 'ticket_number'], 'uq_weighbridge_ticket_number');
        });

        Schema::table('delivery_note_items', function (Blueprint $table): void {
            $table->string('uom', 20)->nullable();
            $table->uuid('weighbridge_ticket_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('delivery_note_items', function (Blueprint $table): void {
            $table->dropColumn(['uom', 'weighbridge_ticket_id']);
        });

        Schema::dropIfExists('weighbridge_tickets');
        Schema::dropIfExists('weighbridge_readings');
        Schema::dropIfExists('weighbridge_vehicles');
    }
};
