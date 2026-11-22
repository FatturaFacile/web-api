<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Listeners;

use Modules\Manufacturing\Events\MesNotificationEvent;
use Modules\Manufacturing\Jobs\PushMesReferenceChangeJob;
use Modules\Manufacturing\Services\MesSync\MesCoreClient;

/**
 * Forwards the change notifications (order released, shift calendar changed, ...) to the MES Core as a queued push,
 * after the transaction of the change commits. Does nothing while the MES Core base URL is not configured.
 */
final readonly class PushMesReferenceChangeListener
{
    public function __construct(private MesCoreClient $client) {}

    /**
     * Queues the push of the change.
     */
    public function handle(MesNotificationEvent $event): void
    {
        if (! $this->client->enabled()) {
            return;
        }

        PushMesReferenceChangeJob::dispatch($event->tenantId, $event->resource, $event->entityId, $event->changeSeq);
    }
}
