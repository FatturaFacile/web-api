<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Manufacturing\Services\MesSync\MesCoreClient;
use Modules\Manufacturing\Services\MesSync\MesReferenceEventFactory;

/**
 * Tells the MES Core that reference data changed. A failure is retried with a growing delay; once the attempts are used
 * up the MES Core still catches up with its periodic pull and manifest reconcile, so the push is an optimisation of
 * latency, never the only way a change arrives.
 */
final class PushMesReferenceChangeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $resource,
        public readonly string $entityId,
        public readonly int $changeSeq,
    ) {
        $this->afterCommit();
    }

    /**
     * Number of attempts.
     */
    public function tries(): int
    {
        return (int) config('manufacturing_mes.core.push_tries');
    }

    /**
     * Seconds to wait before each retry.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 30, 120, 600];
    }

    /**
     * Sends the notification.
     */
    public function handle(MesCoreClient $client, MesReferenceEventFactory $factory): void
    {
        if (! $client->enabled()) {
            return;
        }

        $client->pushReferenceEvent($factory->make($this->tenantId, $this->resource, $this->entityId, $this->changeSeq));
    }
}
