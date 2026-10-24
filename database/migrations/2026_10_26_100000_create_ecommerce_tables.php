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
        Schema::create('ecommerce_channels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('driver', 30); // woocommerce, shopify, prestashop
            $table->string('api_url')->nullable(); // optional for Shopify (shop domain is stored in the credentials)
            $table->text('credentials'); // encrypted JSON
            $table->text('webhook_secret')->nullable(); // encrypted
            $table->boolean('is_active')->default(true);
            $table->boolean('sync_stock_enabled')->default(true);
            $table->boolean('sync_orders_enabled')->default(true);
            $table->timestamp('last_sync_at')->nullable();
            $table->json('settings')->nullable(); // order status map, currency, Shopify location, API version
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('ecommerce_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('channel_id')->constrained('ecommerce_channels')->cascadeOnDelete();
            $table->string('entity_type', 20); // product, product_variant, order, contact
            $table->uuid('local_id'); // ERP records use UUID keys
            $table->string('remote_id');
            $table->string('remote_sku')->nullable();
            $table->json('extra_data')->nullable(); // parent_remote_id, attributes, inventory_item_id, stock_available_id...
            $table->timestamps();

            $table->unique(['tenant_id', 'channel_id', 'entity_type', 'remote_id'], 'ecommerce_mappings_remote_unique');
            $table->index(['channel_id', 'entity_type', 'local_id']);
            $table->index(['channel_id', 'remote_sku']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ecommerce_mappings');
        Schema::dropIfExists('ecommerce_channels');
    }
};
