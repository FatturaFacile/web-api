<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional FatturaPA blocks carried as data with the official element names: `sdi_extra` on the invoice (REA, fiscal
     * representative, order and contract references, SAL, transport, payment details...) and `sdi_data` on a line
     * (article codes, unit of measure, period, other management data).
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->json('sdi_extra')->nullable();
        });

        Schema::table('invoice_items', function (Blueprint $table): void {
            $table->json('sdi_data')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('sdi_extra');
        });

        Schema::table('invoice_items', function (Blueprint $table): void {
            $table->dropColumn('sdi_data');
        });
    }
};
