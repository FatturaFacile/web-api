<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

/**
 * A production order was completed, closed or cancelled.
 */
final class MesWorkOrderClosedEvent extends MesNotificationEvent
{
    /**
     * Name of the event in the outbox.
     */
    public function getEventName(): string
    {
        return 'mfg.mes.work_order.closed';
    }
}
