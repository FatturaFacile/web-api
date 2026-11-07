<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Supplementary charges on products, such as a recycling fee or a duty on a substance: the kind of charge with its
     * ledger account, the tenant's rates with their validity, and which products carry it. No rate is seeded.
     */
    public function up(): void
    {
        Schema::create('product_charge_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('code', 50);
            $table->string('name', 150);
            $table->string('calculation_basis', 20)->default('per_unit');
            $table->uuid('revenue_account_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('revenue_account_id')->references('id')->on('chart_of_accounts')->restrictOnDelete();
            $table->unique(['tenant_id', 'code'], 'uq_product_charge_type');
        });

        Schema::create('product_charge_rates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('product_charge_type_id')->index();
            $table->decimal('rate', 14, 6);
            $table->string('currency', 3);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('product_charge_type_id')->references('id')->on('product_charge_types')->cascadeOnDelete();
        });

        Schema::create('product_charge_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('product_charge_type_id')->index();
            $table->uuid('product_id')->index();
            $table->decimal('quantity_per_unit', 14, 6)->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('product_charge_type_id')->references('id')->on('product_charge_types')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->unique(['product_charge_type_id', 'product_id'], 'uq_product_charge_assignment');
        });

        Schema::table('invoice_items', function (Blueprint $table): void {
            $table->uuid('product_charge_type_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table): void {
            $table->dropColumn('product_charge_type_id');
        });

        Schema::dropIfExists('product_charge_assignments');
        Schema::dropIfExists('product_charge_rates');
        Schema::dropIfExists('product_charge_types');
    }
};
