<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Services\LegacyStockMovementImporter;

/**
 * Books the stock of the app-style movements (product, no stock item) into the inventory module as opening balances,
 * so the module's quants hold the stock the app showed. Idempotent and non-destructive: see LegacyStockMovementImporter.
 * Nothing to undo on rollback: the opening movements are regular stock history.
 */
return new class extends Migration
{
    public function up(): void
    {
        $result = app(LegacyStockMovementImporter::class)->import();

        if ($result['groups'] > 0) {
            Log::info('Legacy stock movements booked into the inventory module.', $result);
        }
    }

    public function down(): void {}
};
