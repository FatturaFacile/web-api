<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * price_list_items is read by two models: the legacy PriceListItem and the Invoicing module SalesPriceListItem, which
 * extends the enterprise base model and therefore filters deleted_at. The table never had the column, so every query of
 * SalesPriceListItem failed once its tenant scope could run (the table had no tenant_id either: see the tenant policy
 * migration). The nullable column is added; nothing else changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('price_list_items') && ! Schema::hasColumn('price_list_items', 'deleted_at')) {
            Schema::table('price_list_items', static function (Blueprint $table): void {
                $table->softDeletes();
            });
        }
    }

    /**
     * The column may hold deletion marks written since: it is kept.
     */
    public function down(): void {}
};
