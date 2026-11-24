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
        Schema::create('product_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->string('name', 100);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::table('product_categories', function (Blueprint $table): void {
            $table->foreign('parent_id')
                ->references('id')
                ->on('product_categories')
                ->nullOnDelete();
        });

        Schema::create('product_brands', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 100);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('units_of_measure', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 10); // PZ, KG, M, L, H
            $table->string('name', 50);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('price_lists', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('currency', 3)->default('EUR');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'is_default']);
        });

        Schema::create('price_list_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('price_list_id')->constrained('price_lists')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('price', 15, 4);
            $table->decimal('min_quantity', 12, 4)->default(1); // quantity-break pricing
            $table->timestamps();

            $table->unique(['price_list_id', 'product_id', 'min_quantity']);
        });

        Schema::create('payment_terms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 100);
            $table->unsignedSmallInteger('days')->default(30);
            $table->boolean('end_of_month')->default(false);
            $table->decimal('discount_percent', 5, 2)->nullable(); // early-payment discount
            $table->unsignedSmallInteger('discount_days')->nullable();
            $table->string('sdi_terms_code', 4)->nullable(); // TP01-TP03
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('payment_term_installments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_term_id')->constrained('payment_terms')->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence');
            $table->decimal('percent', 5, 2);
            $table->unsignedSmallInteger('days');
            $table->timestamps();

            $table->unique(['payment_term_id', 'sequence']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->foreignUuid('category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->foreignUuid('brand_id')->nullable()->constrained('product_brands')->nullOnDelete();
            $table->foreignUuid('unit_of_measure_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
        });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->foreignUuid('price_list_id')->nullable()->constrained('price_lists')->nullOnDelete();
            $table->foreignUuid('payment_term_id')->nullable()->constrained('payment_terms')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('price_list_id');
            $table->dropConstrainedForeignId('payment_term_id');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('category_id');
            $table->dropConstrainedForeignId('brand_id');
            $table->dropConstrainedForeignId('unit_of_measure_id');
        });

        Schema::table('product_categories', function (Blueprint $table): void {
            $table->dropForeign(['parent_id']);
        });

        Schema::dropIfExists('payment_term_installments');
        Schema::dropIfExists('payment_terms');
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
        Schema::dropIfExists('units_of_measure');
        Schema::dropIfExists('product_brands');
        Schema::dropIfExists('product_categories');
    }
};
