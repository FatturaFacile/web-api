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
            if (! Schema::hasColumn('invoices', 'total_due')) {
                $table->decimal('total_due', 15, 2)->nullable();
            }
            if (! Schema::hasColumn('invoices', 'withholding_tax_type')) {
                $table->string('withholding_tax_type', 4)->nullable();
            }
            if (! Schema::hasColumn('invoices', 'withholding_tax_reason')) {
                $table->string('withholding_tax_reason', 2)->nullable();
            }
            if (! Schema::hasColumn('invoices', 'withholding_tax_taxable_percent')) {
                $table->decimal('withholding_tax_taxable_percent', 5, 2)->nullable();
            }
            if (! Schema::hasColumn('invoices', 'withholding_tax_base')) {
                $table->decimal('withholding_tax_base', 15, 2)->nullable();
            }
            if (! Schema::hasColumn('invoices', 'withholding_tax_includes_fund')) {
                $table->boolean('withholding_tax_includes_fund')->default(false);
            }
            if (! Schema::hasColumn('invoices', 'fund_vat_rate')) {
                $table->decimal('fund_vat_rate', 5, 2)->nullable();
            }
            if (! Schema::hasColumn('invoices', 'fund_natura')) {
                $table->string('fund_natura', 4)->nullable();
            }
            if (! Schema::hasColumn('invoices', 'fund_withholding_applies')) {
                $table->boolean('fund_withholding_applies')->default(false);
            }
            if (! Schema::hasColumn('invoices', 'enasarco_percent')) {
                $table->decimal('enasarco_percent', 5, 2)->nullable();
            }
            if (! Schema::hasColumn('invoices', 'enasarco_commissions')) {
                $table->decimal('enasarco_commissions', 15, 2)->nullable();
            }
            if (! Schema::hasColumn('invoices', 'enasarco_taxable')) {
                $table->decimal('enasarco_taxable', 15, 2)->nullable();
            }
            if (! Schema::hasColumn('invoices', 'enasarco_amount')) {
                $table->decimal('enasarco_amount', 15, 2)->default(0);
            }
            if (! Schema::hasColumn('invoices', 'stamp_duty_charged_to_customer')) {
                $table->boolean('stamp_duty_charged_to_customer')->default(false);
            }
            if (! Schema::hasColumn('invoices', 'legal_notices')) {
                $table->json('legal_notices')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'vat_summary')) {
                $table->json('vat_summary')->nullable();
            }
        });

        Schema::table('invoice_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('invoice_items', 'natura')) {
                $table->string('natura', 4)->nullable();
            }
            if (! Schema::hasColumn('invoice_items', 'withholding_applies')) {
                $table->boolean('withholding_applies')->default(true);
            }
            if (! Schema::hasColumn('invoice_items', 'fund_applies')) {
                $table->boolean('fund_applies')->default(true);
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table): void {
            $cols = array_values(array_filter(
                ['natura', 'withholding_applies', 'fund_applies'],
                static fn (string $col): bool => Schema::hasColumn('invoice_items', $col),
            ));
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $cols = array_values(array_filter([
                'total_due', 'withholding_tax_taxable_percent',
                'withholding_tax_base', 'withholding_tax_includes_fund', 'fund_natura',
                'fund_withholding_applies', 'enasarco_commissions', 'enasarco_taxable',
                'stamp_duty_charged_to_customer', 'legal_notices', 'vat_summary',
            ], static fn (string $col): bool => Schema::hasColumn('invoices', $col)));
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};
