<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Carriers with their driver and credentials, the shipments booked with them and the tracking events received.
     */
    public function up(): void
    {
        Schema::create('shipping_carriers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('code', 50);
            $table->string('name', 150);
            $table->string('driver', 50)->default('local');
            $table->string('contract_number', 100)->nullable();
            $table->text('credentials')->nullable();
            $table->string('tracking_url_template', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code'], 'uq_shipping_carrier_code');
        });

        Schema::create('shipments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('shipping_carrier_id');
            $table->uuid('stock_packing_list_id')->nullable()->index();
            $table->uuid('delivery_note_id')->nullable()->index();
            $table->string('shipment_number', 100);
            $table->string('status', 30)->default('booked')->index();
            $table->string('tracking_number', 150)->nullable()->index();
            $table->string('carrier_reference', 150)->nullable();
            $table->jsonb('recipient');
            $table->integer('packages_count')->default(1);
            $table->decimal('gross_weight_kg', 12, 3)->nullable();
            $table->string('label_format', 10)->nullable();
            $table->longText('label_content')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('shipping_carrier_id')->references('id')->on('shipping_carriers')->restrictOnDelete();
            $table->foreign('stock_packing_list_id')->references('id')->on('stock_packing_lists')->nullOnDelete();
            $table->foreign('delivery_note_id')->references('id')->on('delivery_notes')->nullOnDelete();
            $table->unique(['tenant_id', 'shipment_number'], 'uq_shipment_number');
        });

        Schema::create('shipment_tracking_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('shipment_id')->index();
            $table->string('status', 30);
            $table->string('description', 255)->nullable();
            $table->string('location', 150)->nullable();
            $table->timestamp('occurred_at');
            $table->string('source', 20)->default('manual');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('shipment_id')->references('id')->on('shipments')->cascadeOnDelete();
            $table->unique(['shipment_id', 'status', 'occurred_at'], 'uq_shipment_tracking_event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_tracking_events');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('shipping_carriers');
    }
};
