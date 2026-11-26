<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mod 052 — product catalog: polymorphic product types, multi-attribute variant matrices, phantom and physical kits, multi-level
 * units of measure with packaging hierarchies and CH / IT customs and tax classification.
 *
 * The existing `products` table stays the single product row (variants are product rows pointing to their parent); this migration
 * adds the catalog columns to it and the satellite tables. Dialect-agnostic (SQLite and PostgreSQL); tenant isolation is the
 * tenant_id column plus the TenantScope of the models.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('product_type', 20)->default('storable')->index();
            $table->string('kit_type', 20)->nullable()->index();
            $table->foreignUuid('purchase_uom_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            $table->foreignUuid('sales_uom_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            $table->string('uom_rounding', 12)->default('half_up');
            $table->unsignedTinyInteger('uom_precision')->default(4);
            $table->decimal('price_delta', 18, 4)->nullable();
            $table->string('price_delta_type', 10)->nullable();
            $table->decimal('cost_delta', 18, 4)->nullable();
            $table->string('cost_delta_type', 10)->nullable();
            $table->boolean('price_overridden')->default(false);
            $table->boolean('cost_overridden')->default(false);
            $table->foreignUuid('vat_code_it_id')->nullable()->constrained('vat_codes')->nullOnDelete();
            $table->foreignUuid('vat_code_ch_id')->nullable()->constrained('vat_codes')->nullOnDelete();
        });

        Schema::create('catalog_attributes', function (Blueprint $table): void {
            $this->base($table);
            $table->string('code', 40);
            $table->json('name');
            $table->string('sku_token', 8);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $this->audit($table);
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('catalog_attribute_values', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('attribute_id')->constrained('catalog_attributes')->cascadeOnDelete();
            $table->string('code', 40);
            $table->json('label');
            $table->string('sku_token', 10);
            $table->decimal('price_delta', 18, 4)->nullable();
            $table->string('price_delta_type', 10)->nullable();
            $table->decimal('cost_delta', 18, 4)->nullable();
            $table->string('cost_delta_type', 10)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $this->audit($table);
            $table->unique(['attribute_id', 'code']);
        });

        Schema::create('catalog_product_axes', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('attribute_id')->constrained('catalog_attributes')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $this->audit($table);
            $table->unique(['product_id', 'attribute_id']);
        });

        Schema::create('catalog_variant_values', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('variant_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('attribute_id')->constrained('catalog_attributes')->cascadeOnDelete();
            $table->foreignUuid('attribute_value_id')->constrained('catalog_attribute_values')->cascadeOnDelete();
            $this->audit($table);
            $table->unique(['variant_id', 'attribute_id']);
        });

        Schema::create('catalog_packagings', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('role', 12)->default('logistic');
            $table->foreignUuid('uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->uuid('parent_packaging_id')->nullable();
            $table->decimal('units_per_parent', 20, 6);
            $table->decimal('quantity_base', 20, 6);
            $table->unsignedInteger('level')->default(1);
            $table->string('gtin', 14)->nullable();
            $this->audit($table);
            $table->unique(['product_id', 'code']);
        });

        Schema::create('catalog_kit_components', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('kit_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('component_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 20, 6);
            $table->boolean('is_optional')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $this->audit($table);
            $table->unique(['kit_id', 'component_id']);
        });
    }

    public function down(): void
    {
        foreach (['catalog_kit_components', 'catalog_packagings', 'catalog_variant_values', 'catalog_product_axes', 'catalog_attribute_values', 'catalog_attributes'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('purchase_uom_id');
            $table->dropConstrainedForeignId('sales_uom_id');
            $table->dropConstrainedForeignId('vat_code_it_id');
            $table->dropConstrainedForeignId('vat_code_ch_id');
            $table->dropColumn(['product_type', 'kit_type', 'uom_rounding', 'uom_precision', 'price_delta', 'price_delta_type', 'cost_delta', 'cost_delta_type', 'price_overridden', 'cost_overridden']);
        });
    }

    private function base(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
    }

    private function audit(Blueprint $table): void
    {
        $table->uuid('created_by')->nullable();
        $table->uuid('updated_by')->nullable();
        $table->uuid('deleted_by')->nullable();
        $table->timestamps();
        $table->softDeletes();
    }
};
