<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-level BOM management (additive only, safe for `migrate --force`).
 *
 * - Component lines: fixed setup waste (a quantity, not a percentage) and the ordered list of approved substitutes.
 * - Co/by-product lines of a BOM revision with their share of the production cost (`mfg_bom_co_products`).
 * - Engineering changes: the structured diff between the revisions and the approval workflow instance.
 * - Items: preferential origin status and ex-works price for the EUR.1 origin test.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('mfg_bom_components', 'fixed_scrap_quantity')) {
            Schema::table('mfg_bom_components', function (Blueprint $table): void {
                $table->decimal('fixed_scrap_quantity', 15, 4)->default(0);
            });
        }
        if (! Schema::hasColumn('mfg_bom_components', 'substitute_item_ids')) {
            Schema::table('mfg_bom_components', function (Blueprint $table): void {
                $table->json('substitute_item_ids')->nullable();
            });
        }

        if (! Schema::hasColumn('mfg_engineering_change_orders', 'diff_payload')) {
            Schema::table('mfg_engineering_change_orders', function (Blueprint $table): void {
                $table->json('diff_payload')->nullable();
            });
        }
        if (! Schema::hasColumn('mfg_engineering_change_orders', 'approval_workflow_id')) {
            Schema::table('mfg_engineering_change_orders', function (Blueprint $table): void {
                $table->uuid('approval_workflow_id')->nullable()->index();
            });
        }

        if (! Schema::hasColumn('mfg_items', 'origin_status')) {
            Schema::table('mfg_items', function (Blueprint $table): void {
                $table->string('origin_status', 20)->default('non_originating');
            });
        }
        if (! Schema::hasColumn('mfg_items', 'exw_price')) {
            Schema::table('mfg_items', function (Blueprint $table): void {
                $table->decimal('exw_price', 15, 4)->default(0);
            });
        }

        if (! Schema::hasTable('mfg_bom_co_products')) {
            Schema::create('mfg_bom_co_products', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable()->index();
                $table->uuid('bom_id')->index();
                $table->uuid('product_item_id')->index();
                $table->string('product_type', 20)->default('co_product');
                $table->decimal('output_ratio_qty', 15, 4)->default(1);
                $table->decimal('cost_allocation_percentage', 5, 2)->default(0);
                $table->timestamps();
                $table->softDeletes();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->uuid('deleted_by')->nullable();

                $table->foreign('bom_id')->references('id')->on('mfg_boms')->cascadeOnDelete();
                $table->unique(['bom_id', 'product_item_id']);
            });
        }
    }

    public function down(): void
    {
        // Non-destructive enterprise migration policy: the added columns and tables hold engineering history.
    }
};
