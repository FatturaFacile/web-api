<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

/**
 * A reason code or one of its labels changed.
 */
final class MesReasonCodeCatalogUpdatedEvent extends MesNotificationEvent
{
    /**
     * Name of the event in the outbox.
     */
    public function getEventName(): string
    {
        return 'mfg.mes.reason_code.catalog_updated';
    }
}
