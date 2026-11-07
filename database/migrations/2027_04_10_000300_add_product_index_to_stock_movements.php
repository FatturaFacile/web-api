<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The stock of a product sums its movements by `product_id`, which had no index of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS stock_movements_product_id_index ON stock_movements (product_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS stock_movements_product_id_index');
    }
};
