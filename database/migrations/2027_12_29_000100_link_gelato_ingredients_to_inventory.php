<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mod 398: links a technical gelato ingredient to an inventory stock item, so the raw materials a lot consumes are deducted
 * from stock. `stock_unit_grams` is the number of grams in one stock unit of the item (1000 when the item is kept in kg).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gelato_technical_ingredients', function (Blueprint $table): void {
            $table->uuid('stock_item_id')->nullable();
            $table->decimal('stock_unit_grams', 12, 3)->default(1000);
            $table->index(['tenant_id', 'stock_item_id'], 'gelato_ingredients_stock_item_index');
        });
    }

    public function down(): void
    {
        Schema::table('gelato_technical_ingredients', function (Blueprint $table): void {
            $table->dropIndex('gelato_ingredients_stock_item_index');
            $table->dropColumn(['stock_item_id', 'stock_unit_grams']);
        });
    }
};
