<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A variant is a product row of its own (own SKU, barcode, price and stock) pointing to its parent product.
     * The SKU stays unique per tenant, which also makes every variant SKU unique.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->foreignUuid('parent_id')->nullable()->constrained('products')->cascadeOnDelete();
            $table->boolean('is_variant')->default(false)->index();
            $table->json('attributes')->nullable(); // e.g. {"Size": "M", "Color": "Red"}
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['is_variant', 'attributes']);
        });
    }
};
