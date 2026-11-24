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
        Schema::table('quotes', function (Blueprint $table): void {
            $table->text('custom_text')->nullable();
            $table->text('signature_data')->nullable();
            $table->timestamp('signed_at')->nullable();
        });

        Schema::table('sales_order_items', function (Blueprint $table): void {
            $table->decimal('quantity_delivered', 12, 4)->default(0);
        });

        Schema::table('delivery_notes', function (Blueprint $table): void {
            $table->foreignUuid('sales_order_id')->nullable()->after('contact_id')->constrained('sales_orders')->nullOnDelete();
            $table->text('shipping_address')->nullable();
            $table->string('courier')->nullable();
            $table->boolean('show_prices')->default(false);
            $table->string('status', 30)->default('draft')->index(); // draft, issued, invoiced
        });

        Schema::create('delivery_note_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('delivery_note_id')->constrained('delivery_notes')->cascadeOnDelete();
            $table->foreignUuid('sales_order_item_id')->nullable()->constrained('sales_order_items')->nullOnDelete();
            $table->uuid('product_id')->nullable()->index();
            $table->string('description');
            $table->decimal('quantity', 12, 4)->default(1);
            $table->decimal('unit_price', 15, 4)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->timestamps();
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->foreignUuid('delivery_note_id')->nullable()->constrained('delivery_notes')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('delivery_note_id');
        });

        Schema::dropIfExists('delivery_note_items');

        Schema::table('delivery_notes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sales_order_id');
            $table->dropColumn(['shipping_address', 'courier', 'show_prices', 'status']);
        });

        Schema::table('sales_order_items', function (Blueprint $table): void {
            $table->dropColumn('quantity_delivered');
        });

        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropColumn(['custom_text', 'signature_data', 'signed_at']);
        });
    }
};
