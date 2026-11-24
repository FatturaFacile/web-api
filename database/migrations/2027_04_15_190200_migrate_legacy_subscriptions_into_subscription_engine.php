<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Modules\SubscriptionBilling\Services\LegacySubscriptionMigrator;

/**
 * Data only: copies the subscriptions of the former recurring billing into `sub_subscriptions` (same ids and numbers,
 * billed runs as invoiced periods, see LegacySubscriptionMigrator). Idempotent on the legacy id, so running it again,
 * or running `php artisan subscriptions:migrate-legacy` afterwards, copies only what is still missing. The legacy
 * rows are never changed or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        $counts = app(LegacySubscriptionMigrator::class)->migrate();

        Log::info('Legacy subscriptions migrated to sub_subscriptions', $counts);
    }

    /**
     * Not reversed: the copies carry their own billing history from now on.
     */
    public function down(): void {}
};
