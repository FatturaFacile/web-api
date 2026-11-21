<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The co/by-product lines of a BOM (`mfg_bom_co_products`) were created without the three audit columns every business
 * table carries (created_by, updated_by, deleted_by). A follow-up migration, because the table already exists wherever the
 * original migration ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mfg_bom_co_products')) {
            return;
        }

        foreach (['created_by', 'updated_by', 'deleted_by'] as $column) {
            if (! Schema::hasColumn('mfg_bom_co_products', $column)) {
                Schema::table('mfg_bom_co_products', function (Blueprint $table) use ($column): void {
                    $table->uuid($column)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        // Non-destructive enterprise migration policy: the audit columns hold the history of the lines.
    }
};
