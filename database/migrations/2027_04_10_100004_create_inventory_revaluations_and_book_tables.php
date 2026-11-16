<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FatturaFacile/FatturaFacile#144 — inventory revaluations (landed cost, valuation method
 * change, manual) and immutable year-end inventory book exports (libro inventari).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_revaluations')) {
            Schema::create('inventory_revaluations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->string('source_type', 30); // landed_cost, method_change, manual
                $table->uuid('source_id')->nullable();
                $table->string('status', 20)->default('posted'); // posted, reversed
                $table->date('posting_date');
                $table->decimal('total_inventory_delta', 14, 2)->default(0);
                $table->decimal('total_cogs_delta', 14, 2)->default(0);
                $table->decimal('total_variance_delta', 14, 2)->default(0);
                $table->uuid('journal_entry_id')->nullable();
                $table->uuid('reversal_of_id')->nullable();
                $table->json('lines')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['source_type', 'source_id'], 'idx_inv_reval_source');
            });
        }

        if (! Schema::hasTable('inventory_book_exports')) {
            Schema::create('inventory_book_exports', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->integer('year');
                $table->date('as_of');
                $table->uuid('warehouse_id')->nullable();
                $table->string('status', 20)->default('generated');
                $table->integer('rows_count')->default(0);
                $table->decimal('total_quantity', 18, 4)->default(0);
                $table->decimal('total_value', 14, 2)->default(0);
                $table->decimal('gl_inventory_balance', 14, 2)->default(0);
                $table->decimal('difference', 14, 2)->default(0);
                $table->json('method_breakdown')->nullable();
                $table->longText('payload');
                $table->string('checksum', 64);
                $table->uuid('generated_by')->nullable();
                $table->timestamp('generated_at');
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'year'], 'idx_inv_book_year');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_book_exports');
        Schema::dropIfExists('inventory_revaluations');
    }
};
