<?php

declare(strict_types=1);

namespace Modules\Integrations\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Modules\Integrations\Models\WebhookDelivery;

/**
 * Applies the retention policy of the delivery log (config webhooks.retention), once a day:
 * - succeeded deliveries are deleted after succeeded_days (default 30);
 * - dead letters after dead_days (default 90): longer, because they wait for a manual redelivery;
 * - test pings after test_days (default 7), whatever their outcome;
 * - deliveries that are pending or retrying are never deleted.
 * The age is counted from the last change of the delivery (updated_at: when it succeeded or died). The attempts go with
 * their delivery (cascade). The audit trail of the endpoint changes is not part of this log and is not touched.
 */
final class PruneWebhookDeliveriesCommand extends Command
{
    protected $signature = 'webhooks:prune';

    protected $description = 'Delete webhook deliveries (and their attempts) older than the retention policy';

    public function handle(): int
    {
        $policy = (array) config('webhooks.retention');
        $now = now();

        $tests = $this->prune(fn (Builder $query) => $query->where('is_test', true)->whereIn('status', [WebhookDelivery::StatusSucceeded, WebhookDelivery::StatusDead])
            ->where('updated_at', '<', $now->copy()->subDays((int) ($policy['test_days'] ?? 7))));

        $succeeded = $this->prune(fn (Builder $query) => $query->where('is_test', false)->where('status', WebhookDelivery::StatusSucceeded)
            ->where('updated_at', '<', $now->copy()->subDays((int) ($policy['succeeded_days'] ?? 30))));

        $dead = $this->prune(fn (Builder $query) => $query->where('is_test', false)->where('status', WebhookDelivery::StatusDead)
            ->where('updated_at', '<', $now->copy()->subDays((int) ($policy['dead_days'] ?? 90))));

        $this->info("Deleted {$succeeded} succeeded, {$dead} dead and {$tests} test webhook deliveries.");

        return self::SUCCESS;
    }

    /**
     * @param  callable(Builder<WebhookDelivery>): mixed  $scope
     */
    private function prune(callable $scope): int
    {
        $deleted = 0;

        do {
            $query = WebhookDelivery::withoutGlobalScopes();
            $scope($query);
            $ids = $query->limit(1000)->pluck('id');
            $count = WebhookDelivery::withoutGlobalScopes()->whereIn('id', $ids)->delete();
            $deleted += $count;
        } while ($count > 0);

        return $deleted;
    }
}
