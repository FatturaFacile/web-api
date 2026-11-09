<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Modules\GeneralLedger\Services\FunctionalCurrencyBackfill;

return new class extends Migration
{
    /**
     * Fills the functional debit and credit of the journal lines posted before the columns existed (see
     * FunctionalCurrencyBackfill for the rules). Idempotent: only lines with both columns null are touched, and a
     * foreign-currency line without a usable rate stays null, to be listed by
     * `php artisan general-ledger:functional-currency-audit`.
     */
    public function up(): void
    {
        (new FunctionalCurrencyBackfill)->run();
    }

    /**
     * The values are data derived from the lines themselves; the previous migration drops the columns.
     */
    public function down(): void {}
};
