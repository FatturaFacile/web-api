<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FatturaFacile/FatturaFacile#144 — landed cost documents (freight, customs, insurance...),
 * their target goods receipts and the persisted allocations once applied.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('landed_cost_documents')) {
            Schema::create('landed_cost_documents', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->string('number', 50);
                $table->string('status', 20)->default('draft')->index(); // draft, applied, reversed
                $table->string('cost_type', 30); // freight, customs_duty, insurance, brokerage, other
                $table->string('allocation_key', 20); // value, weight, volume, quantity
                $table->string('currency', 3)->default('EUR');
                $table->decimal('exchange_rate', 18, 8)->default(1);
                $table->decimal('total_amount', 14, 2);
                $table->uuid('supplier_invoice_id')->nullable()->index(); // passive invoice ref (Mod 048), no hard FK
                $table->date('document_date');
                $table->text('notes')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestamp('applied_at')->nullable();
                $table->uuid('applied_by')->nullable();
                $table->timestamp('reversed_at')->nullable();
                $table->uuid('reversed_by')->nullable();
                $table->string('reversal_reason', 500)->nullable();
                $table->uuid('journal_entry_id')->nullable();
                $table->uuid('revaluation_id')->nullable();
                $table->uuid('reversal_of_id')->nullable()->index();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'number'], 'uq_landed_cost_number');
            });
        }

        if (! Schema::hasTable('landed_cost_document_receipts')) {
            Schema::create('landed_cost_document_receipts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('landed_cost_document_id');
                $table->uuid('purchase_receipt_id')->index();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('landed_cost_document_id')->references('id')->on('landed_cost_documents')->cascadeOnDelete();
                $table->unique(['landed_cost_document_id', 'purchase_receipt_id'], 'uq_landed_cost_receipt');
            });
        }

        if (! Schema::hasTable('landed_cost_allocations')) {
            Schema::create('landed_cost_allocations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('landed_cost_document_id');
                $table->uuid('purchase_receipt_id')->index();
                $table->uuid('stock_item_id')->index();
                $table->uuid('lot_id')->nullable();
                $table->uuid('cost_layer_id')->nullable()->index();
                $table->string('valuation_method', 50);
                $table->decimal('basis_value', 20, 6);
                $table->decimal('allocated_amount', 14, 2);
                $table->decimal('to_inventory_amount', 14, 2)->default(0);
                $table->decimal('to_cogs_amount', 14, 2)->default(0);
                $table->decimal('to_variance_amount', 14, 2)->default(0);
                $table->decimal('layer_unit_uplift', 14, 4)->default(0);
                $table->decimal('average_cost_uplift', 14, 4)->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('landed_cost_document_id')->references('id')->on('landed_cost_documents')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('landed_cost_allocations');
        Schema::dropIfExists('landed_cost_document_receipts');
        Schema::dropIfExists('landed_cost_documents');
    }
};
