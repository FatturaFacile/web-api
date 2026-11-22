<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\MesSync;

/**
 * Builds the body of a `ref-events` push from a change notification. The push is a payload-less nudge: it names the
 * resource and the sequence of the change and the MES Core answers by pulling the authoritative feed, so there is one
 * representation of every resource (the pull endpoint) and a notification can name an aggregate (an order, a work
 * centre) that the feed serves as several entities. A sequence that does not directly follow the cursor of the MES Core
 * triggers the pull, which is also what repairs a lost push.
 */
final readonly class MesReferenceEventFactory
{
    /**
     * The event of one change.
     *
     * @return array{tenant_id: string, resource: string, id: string, seq: int, revision: int, deleted: false, payload: null}
     */
    public function make(string $tenantId, string $resource, string $entityId, int $changeSeq): array
    {
        return [
            'tenant_id' => $tenantId,
            'resource' => $resource,
            'id' => $entityId,
            'seq' => $changeSeq,
            'revision' => $changeSeq,
            'deleted' => false,
            'payload' => null,
        ];
    }
}
