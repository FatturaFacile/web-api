<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * VAT of a received document with EsigibilitaIVA = S (split payment, art. 17-ter DPR 633/72): the buyer pays it to the
     * tax authority instead of the supplier. Existing documents default to none: files already received are not parsed
     * again.
     */
    public function up(): void
    {
        Schema::table('passive_invoices', function (Blueprint $table): void {
            $table->decimal('split_payment_tax_amount', 15, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('passive_invoices', function (Blueprint $table): void {
            $table->dropColumn('split_payment_tax_amount');
        });
    }
};
