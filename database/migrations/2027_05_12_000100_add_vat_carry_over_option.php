<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant option of the Italian periodic VAT: whether a debt that does not exceed the minimum payment (EUR 100) is carried
 * to the next period (the behaviour so far, and the default) or paid in its own period. The law only says that it "may"
 * be paid together with the next period (Agenzia delle Entrate, circolare 9/E of 2 May 2024).
 *
 * Additive and idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vat_tax_settings') && ! Schema::hasColumn('vat_tax_settings', 'carry_over_below_minimum')) {
            Schema::table('vat_tax_settings', static function (Blueprint $table): void {
                $table->boolean('carry_over_below_minimum')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('vat_tax_settings', 'carry_over_below_minimum')) {
            Schema::table('vat_tax_settings', static function (Blueprint $table): void {
                $table->dropColumn('carry_over_below_minimum');
            });
        }
    }
};
