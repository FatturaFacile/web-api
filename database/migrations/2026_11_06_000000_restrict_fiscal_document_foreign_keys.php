<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // On SQLite, altering foreign keys requires table redefinition which can fail
        // if foreign key checks are active or table constraints have composite relationships.
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        // 1. invoices -> contacts
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropForeign(['contact_id']);
            $table->foreign('contact_id')->references('id')->on('contacts')->restrictOnDelete();
        });

        // 2. payments -> invoices
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign(['invoice_id']);
            $table->foreign('invoice_id')->references('id')->on('invoices')->restrictOnDelete();
        });

        // 3. passive_invoices -> contacts (supplier_id)
        Schema::table('passive_invoices', function (Blueprint $table): void {
            $table->dropForeign(['supplier_id']);
            $table->foreign('supplier_id')->references('id')->on('contacts')->restrictOnDelete();
        });

        // 4. purchase_orders -> contacts (supplier_id)
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropForeign(['supplier_id']);
            $table->foreign('supplier_id')->references('id')->on('contacts')->restrictOnDelete();
        });

        // 5. quotes -> contacts
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropForeign(['contact_id']);
            $table->foreign('contact_id')->references('id')->on('contacts')->restrictOnDelete();
        });

        // 6. sales_orders -> contacts
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropForeign(['contact_id']);
            $table->foreign('contact_id')->references('id')->on('contacts')->restrictOnDelete();
        });

        // 7. delivery_notes -> contacts
        Schema::table('delivery_notes', function (Blueprint $table): void {
            $table->dropForeign(['contact_id']);
            $table->foreign('contact_id')->references('id')->on('contacts')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('delivery_notes', function (Blueprint $table): void {
            $table->dropForeign(['contact_id']);
            $table->foreign('contact_id')->references('id')->on('contacts')->cascadeOnDelete();
        });

        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropForeign(['contact_id']);
            $table->foreign('contact_id')->references('id')->on('contacts')->cascadeOnDelete();
        });

        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropForeign(['contact_id']);
            $table->foreign('contact_id')->references('id')->on('contacts')->cascadeOnDelete();
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropForeign(['supplier_id']);
            $table->foreign('supplier_id')->references('id')->on('contacts')->cascadeOnDelete();
        });

        Schema::table('passive_invoices', function (Blueprint $table): void {
            $table->dropForeign(['supplier_id']);
            $table->foreign('supplier_id')->references('id')->on('contacts')->cascadeOnDelete();
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign(['invoice_id']);
            $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropForeign(['contact_id']);
            $table->foreign('contact_id')->references('id')->on('contacts')->cascadeOnDelete();
        });
    }
};
