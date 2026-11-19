<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #286: withholdings (DatiRitenuta) of received supplier invoices, the source of the CU withholding report.
 * The payment year is derived from supplier_payments.paid_at, so no paid_at column is added to passive_invoices.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('passive_invoice_withholdings')) {
            return;
        }

        Schema::create('passive_invoice_withholdings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('passive_invoice_id')->constrained('passive_invoices')->cascadeOnDelete();
            $table->foreignUuid('supplier_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('type', 4); // RT01-RT06
            $table->string('reason', 2)->nullable(); // CU causale
            $table->decimal('rate', 5, 2)->default(0);
            $table->decimal('taxable_amount', 15, 2)->nullable();
            $table->decimal('amount', 15, 2);
            $table->unsignedSmallInteger('withheld_year')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'withheld_year']);
            $table->index(['tenant_id', 'supplier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passive_invoice_withholdings');
    }
};
