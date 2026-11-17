<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Locked VAT periods of the CH/IT settlement engine: the frozen calculation of a period (Swiss MWST Ziffern or Italian
 * LIPE VP boxes) and its filing status. Also adds the tenant options that drive the engine.
 *
 * Additive and idempotent; dialect-agnostic.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vat_settlement_periods')) {
            Schema::create('vat_settlement_periods', static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('vat_settlement_id')->nullable()->index();
                $table->string('jurisdiction', 2);
                $table->string('period_type', 16);
                $table->date('period_from');
                $table->date('period_to');
                $table->string('regime', 24)->nullable();
                $table->string('basis', 16)->nullable();
                $table->json('calculation');
                $table->decimal('payable_amount', 15, 2)->default(0);
                $table->string('status', 16)->default('DRAFT');
                $table->timestamp('locked_at')->nullable();
                $table->uuid('locked_by')->nullable();
                $table->timestamp('filed_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'jurisdiction', 'period_from', 'period_to'], 'vat_settlement_periods_unique_period');
            });
        }

        if (Schema::hasTable('vat_tax_settings')) {
            Schema::table('vat_tax_settings', static function (Blueprint $table): void {
                if (! Schema::hasColumn('vat_tax_settings', 'mwst_basis')) {
                    $table->string('mwst_basis', 16)->default('agreed');
                }
                if (! Schema::hasColumn('vat_tax_settings', 'lipe_prorata_percent')) {
                    $table->decimal('lipe_prorata_percent', 5, 2)->default(100);
                }
                if (! Schema::hasColumn('vat_tax_settings', 'lipe_advance_amount')) {
                    $table->decimal('lipe_advance_amount', 15, 2)->default(0);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_settlement_periods');

        foreach (['mwst_basis', 'lipe_prorata_percent', 'lipe_advance_amount'] as $column) {
            if (Schema::hasColumn('vat_tax_settings', $column)) {
                Schema::table('vat_tax_settings', static function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
