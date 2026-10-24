<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('supplier_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignUuid('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->string('receipt_number', 50);
            $table->date('date');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'receipt_number']);
        });

        Schema::create('goods_receipt_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('goods_receipt_id')->constrained('goods_receipts')->cascadeOnDelete();
            $table->foreignUuid('purchase_order_item_id')->nullable()->constrained('purchase_order_items')->nullOnDelete();
            $table->uuid('product_id')->nullable()->index();
            $table->string('description');
            $table->decimal('quantity', 12, 4);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->timestamps();
        });

        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->decimal('quantity_received', 12, 4)->default(0);
        });

        Schema::table('passive_invoices', function (Blueprint $table): void {
            $table->string('document_type', 4)->default('TD01'); // TD01 invoice, TD04 credit note, TD05 debit note...
            $table->decimal('total_net', 15, 2)->default(0);
            $table->decimal('total_tax', 15, 2)->default(0);
            $table->string('payment_status', 20)->default('unpaid'); // unpaid, paid
            $table->string('document_hash', 64)->nullable(); // business-key hash used to reject duplicates
            $table->string('sdi_file_name')->nullable();

            $table->unique(['tenant_id', 'document_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('passive_invoices', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'document_hash']);
            $table->dropColumn(['document_type', 'total_net', 'total_tax', 'payment_status', 'document_hash', 'sdi_file_name']);
        });

        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->dropColumn('quantity_received');
        });

        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
    }
};
