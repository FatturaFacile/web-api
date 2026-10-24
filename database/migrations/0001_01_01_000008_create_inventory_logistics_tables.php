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
        Schema::create('products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('sku', 50)->index();
            $table->string('barcode', 50)->nullable()->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit_measure', 10)->default('PZ'); // PZ, KG, M, L, H, etc.
            $table->decimal('cost_price', 15, 4)->default(0);
            $table->decimal('sale_price', 15, 4)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(22.00); // 22% IT or 8.1% CH
            $table->string('hs_code', 20)->nullable(); // Customs Tariff / Dogana IT-CH
            $table->boolean('track_inventory')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'sku']);
        });

        Schema::create('warehouses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 50)->index();
            $table->string('name');
            $table->string('address')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->enum('type', ['in', 'out', 'transfer', 'adjustment'])->index();
            $table->decimal('quantity', 12, 4);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->string('reference_type')->nullable(); // e.g. App\Models\Invoice, DeliveryNote, ProductionOrder
            $table->uuid('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('delivery_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('ddt_number', 50)->index();
            $table->date('date');
            $table->string('transport_reason', 100)->default('Vendita'); // Vendita, Conto Lavorazione, Riparazione
            $table->string('goods_appearance', 100)->default('A vista');
            $table->unsignedInteger('packages_count')->default(1);
            $table->decimal('gross_weight_kg', 10, 2)->nullable();
            $table->string('carrier_name')->nullable();
            $table->string('carrier_license_plate', 30)->nullable();
            $table->dateTime('departure_time')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'ddt_number']);
        });

        Schema::create('customs_declarations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('ddt_id')->nullable()->constrained('delivery_notes')->nullOnDelete();
            $table->string('declaration_number', 50)->index();
            $table->string('mrn_number', 50)->nullable()->index(); // Movement Reference Number (NCTS Dogana)
            $table->string('type', 30)->default('export_it_to_ch'); // export_it_to_ch, import_ch_from_it, t1_transit
            $table->string('customs_office', 100)->nullable(); // e.g. Como Brogeda, Chiasso Strada
            $table->string('customs_agent', 100)->nullable();
            $table->decimal('declared_value', 15, 2);
            $table->string('currency', 3)->default('EUR');
            $table->string('incoterms', 10)->default('DAP'); // DAP, EXW, FCA, DDP
            $table->string('status', 30)->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'declaration_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customs_declarations');
        Schema::dropIfExists('delivery_notes');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('products');
    }
};
