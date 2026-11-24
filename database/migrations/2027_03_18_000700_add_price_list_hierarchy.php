<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nested price lists: a list inherits what it does not set from its parent, moved by its own percentage. Validity
     * reaches single items, so the same tier can repeat for another period.
     */
    public function up(): void
    {
        Schema::table('price_lists', function (Blueprint $table): void {
            $table->uuid('parent_price_list_id')->nullable()->index();
            $table->decimal('price_adjustment_percent', 7, 3)->nullable();

            $table->foreign('parent_price_list_id')->references('id')->on('price_lists')->nullOnDelete();
        });

        Schema::table('price_list_items', function (Blueprint $table): void {
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();

            $table->dropUnique(['price_list_id', 'product_id', 'min_quantity']);
            $table->unique(['price_list_id', 'product_id', 'min_quantity', 'valid_from'], 'uq_price_list_item_tier_period');
        });
    }

    public function down(): void
    {
        Schema::table('price_list_items', function (Blueprint $table): void {
            $table->dropUnique('uq_price_list_item_tier_period');
            $table->unique(['price_list_id', 'product_id', 'min_quantity']);
            $table->dropColumn(['valid_from', 'valid_to']);
        });

        Schema::table('price_lists', function (Blueprint $table): void {
            $table->dropForeign(['parent_price_list_id']);
            $table->dropColumn(['parent_price_list_id', 'price_adjustment_percent']);
        });
    }
};
