<?php

declare(strict_types=1);

namespace Modules\Integrations\Console;

use Illuminate\Console\Command;
use Modules\Integrations\Models\WebhookDelivery;

/**
 * Deletes the log of deliveries that finished (succeeded or dead) more than the retention period ago. A delivery that
 * is still waiting for an attempt is never pruned.
 */
final class PruneWebhookDeliveriesCommand extends Command
{
    protected $signature = 'webhooks:prune';

    protected $description = 'Delete webhook deliveries (and their attempts) older than the retention period';

    public function handle(): int
    {
        $deleted = WebhookDelivery::withoutGlobalScopes()
            ->whereIn('status', [WebhookDelivery::StatusSucceeded, WebhookDelivery::StatusDead])
            ->where('updated_at', '<', now()->subDays((int) config('webhooks.retention_days')))
            ->delete();

        $this->info("Deleted {$deleted} webhook deliveries.");

        return self::SUCCESS;
    }
}
