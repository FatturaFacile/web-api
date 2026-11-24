<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GH-396: Serialized equipment passports, component genealogy and warranty extensions (Module 17 ESM).
 *
 * References to Inventory (stock_serial_numbers), CRM (contacts), Invoicing (invoices) and products are
 * stored as plain UUID columns on purpose: no DB-level FK constraints to avoid cross-module coupling.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('esm_equipment_passports')) {
            Schema::create('esm_equipment_passports', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('stock_serial_number_id')->nullable()->index(); // Module 14 StockSerialNumber (no FK)
                $table->uuid('product_id')->nullable()->index(); // products (no FK)
                $table->string('serial_number', 100);
                $table->uuid('current_owner_customer_id')->index(); // contacts (no FK)
                $table->uuid('original_sales_invoice_id')->nullable()->index(); // invoices (no FK)
                $table->string('customer_type', 3)->default('b2c'); // b2b, b2c
                $table->string('jurisdiction', 2)->default('IT'); // CH, IT
                $table->date('delivery_date');
                $table->string('warranty_policy_type', 40); // statutory_ch_24m, statutory_it_b2c_24m, statutory_it_b2b_12m, extended_care_pack
                $table->date('warranty_start_date')->nullable();
                $table->date('warranty_expiry_date')->index();
                $table->string('warranty_source', 30)->default('statutory'); // statutory, contract_clause, extension
                $table->unsignedSmallInteger('contract_warranty_months')->nullable();
                $table->string('contract_clause_reference', 150)->nullable();
                $table->string('installed_firmware_version', 60)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'serial_number', 'product_id'], 'esm_passports_tenant_serial_product_unique');
                $table->index(['tenant_id', 'serial_number'], 'esm_passports_tenant_serial_idx');
            });
        }

        if (! Schema::hasTable('esm_equipment_components')) {
            Schema::create('esm_equipment_components', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('equipment_passport_id')->constrained('esm_equipment_passports')->cascadeOnDelete();
                $table->string('position', 100);
                $table->string('component_serial', 100);
                $table->uuid('component_product_id')->nullable()->index();
                $table->string('role', 60)->nullable();
                $table->timestamp('installed_at')->nullable();
                $table->timestamp('removed_at')->nullable();
                $table->string('source', 20)->default('as_built'); // as_built, as_maintained
                $table->uuid('replaced_by_component_id')->nullable()->index();
                $table->uuid('rma_order_id')->nullable()->index();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['equipment_passport_id', 'removed_at'], 'esm_components_passport_active_idx');
            });
        }

        if (! Schema::hasTable('esm_equipment_warranty_extensions')) {
            Schema::create('esm_equipment_warranty_extensions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('equipment_passport_id')->constrained('esm_equipment_passports')->cascadeOnDelete();
                $table->string('name', 150);
                $table->unsignedSmallInteger('months');
                $table->date('starts_at');
                $table->date('ends_at');
                $table->string('reference', 100)->nullable();
                $table->decimal('price', 18, 2)->default(0.00);
                $table->string('currency', 3)->default('EUR');
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('esm_equipment_warranty_extensions');
        Schema::dropIfExists('esm_equipment_components');
        Schema::dropIfExists('esm_equipment_passports');
    }
};
