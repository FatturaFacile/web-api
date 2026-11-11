<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_reorder_rules', function (Blueprint $table): void {
            // Set while the available stock is below min_stock_level: it makes the below-minimum event fire on the crossing only.
            $table->timestamp('below_minimum_since')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stock_reorder_rules', function (Blueprint $table): void {
            $table->dropColumn('below_minimum_since');
        });
    }
};
