<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Modules\Manufacturing\Services\LegacyProductionOrderMigrator;

/**
 * Data only: copies the app's former bills of materials, production orders and their stages into the manufacturing
 * module (same ids, see LegacyProductionOrderMigrator). Idempotent on the legacy ids, so running it again copies only
 * what is still missing. The legacy rows are never changed or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        $counts = app(LegacyProductionOrderMigrator::class)->migrate();

        Log::info('Legacy production orders migrated to the manufacturing module', $counts);
    }

    /**
     * Not reversed: the copies carry their own production history from now on.
     */
    public function down(): void {}
};
