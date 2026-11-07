<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Car workshops and body shops: work folders with their lines, the stock of new and used vehicles for sale, and the
 * recurring services (tyre storage, maintenance plans) billed through the existing subscriptions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_folders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('folder_number', 40);
            $table->foreignUuid('vehicle_id')->constrained('vehicles')->restrictOnDelete();
            $table->foreignUuid('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->string('kind', 20)->default('mechanics');
            $table->string('status', 20)->default('open');
            $table->timestamp('received_at')->nullable();
            $table->timestamp('promised_at')->nullable();
            $table->unsignedInteger('mileage_in')->nullable();
            $table->unsignedInteger('mileage_out')->nullable();
            $table->text('complaint')->nullable();
            $table->text('diagnosis')->nullable();
            $table->string('currency', 3)->default('CHF');
            $table->foreignUuid('quote_id')->nullable()->constrained('quotes')->nullOnDelete();
            $table->foreignUuid('delivery_note_id')->nullable()->constrained('delivery_notes')->nullOnDelete();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'folder_number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('workshop_folder_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('folder_id')->constrained('workshop_folders')->cascadeOnDelete();
            $table->string('line_type', 20);
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('description', 500);
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_price', 15, 4);
            $table->decimal('discount_percentage', 5, 2)->default(0);
            $table->decimal('tax_rate', 5, 2);
            $table->decimal('line_total', 15, 2);
            $table->foreignUuid('technician_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'folder_id']);
        });

        Schema::create('workshop_vehicle_stock', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('stock_number', 40);
            $table->string('condition', 10);
            $table->string('vin', 30)->nullable();
            $table->string('license_plate', 20)->nullable();
            $table->string('make', 50);
            $table->string('model', 50);
            $table->unsignedSmallInteger('year')->nullable();
            $table->unsignedInteger('mileage_km')->default(0);
            $table->foreignUuid('supplier_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->decimal('purchase_price', 15, 2)->nullable();
            $table->decimal('list_price', 15, 2);
            $table->string('currency', 3)->default('CHF');
            $table->string('status', 20)->default('in_stock');
            $table->foreignUuid('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'stock_number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('workshop_vehicle_sales', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('stock_id')->unique()->constrained('workshop_vehicle_stock')->restrictOnDelete();
            $table->foreignUuid('buyer_contact_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignUuid('vehicle_id')->constrained('vehicles')->restrictOnDelete();
            $table->date('sale_date');
            $table->decimal('sale_price', 15, 2);
            $table->decimal('tax_rate', 5, 2);
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'sale_date']);
        });

        Schema::create('workshop_recurring_services', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('vehicle_id')->constrained('vehicles')->restrictOnDelete();
            $table->foreignUuid('tire_hotel_record_id')->nullable()->constrained('tire_hotel_records')->nullOnDelete();
            $table->foreignUuid('subscription_id')->constrained('subscriptions')->restrictOnDelete();
            $table->string('service_type', 30);
            $table->timestamps();

            $table->index(['tenant_id', 'vehicle_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_recurring_services');
        Schema::dropIfExists('workshop_vehicle_sales');
        Schema::dropIfExists('workshop_vehicle_stock');
        Schema::dropIfExists('workshop_folder_lines');
        Schema::dropIfExists('workshop_folders');
    }
};
