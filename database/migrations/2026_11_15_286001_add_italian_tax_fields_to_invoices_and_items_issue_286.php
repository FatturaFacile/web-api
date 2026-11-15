<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #286: persisted result of the Italian fiscal calculation (cassa, ritenuta, ENASARCO, bollo, split payment).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->decimal('total_due', 15, 2)->nullable();
            $table->string('withholding_tax_type', 4)->nullable(); // RT01-RT06
            $table->string('withholding_tax_reason', 2)->nullable(); // CU causale (A, R, ...)
            $table->decimal('withholding_tax_taxable_percent', 5, 2)->nullable();
            $table->decimal('withholding_tax_base', 15, 2)->nullable();
            $table->boolean('withholding_tax_includes_fund')->default(false);
            $table->decimal('fund_vat_rate', 5, 2)->nullable();
            $table->string('fund_natura', 4)->nullable();
            $table->boolean('fund_withholding_applies')->default(false);
            $table->decimal('enasarco_percent', 5, 2)->nullable();
            $table->decimal('enasarco_commissions', 15, 2)->nullable();
            $table->decimal('enasarco_taxable', 15, 2)->nullable();
            $table->decimal('enasarco_amount', 15, 2)->nullable();
            $table->boolean('stamp_duty_charged_to_customer')->default(false);
            $table->json('legal_notices')->nullable();
            $table->json('vat_summary')->nullable();
        });

        Schema::table('invoice_items', function (Blueprint $table): void {
            $table->string('natura', 4)->nullable();
            $table->boolean('withholding_applies')->default(true);
            $table->boolean('fund_applies')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table): void {
            $table->dropColumn(['natura', 'withholding_applies', 'fund_applies']);
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn([
                'total_due', 'withholding_tax_type', 'withholding_tax_reason', 'withholding_tax_taxable_percent',
                'withholding_tax_base', 'withholding_tax_includes_fund', 'fund_vat_rate', 'fund_natura',
                'fund_withholding_applies', 'enasarco_percent', 'enasarco_commissions', 'enasarco_taxable',
                'enasarco_amount', 'stamp_duty_charged_to_customer', 'legal_notices', 'vat_summary',
            ]);
        });
    }
};
