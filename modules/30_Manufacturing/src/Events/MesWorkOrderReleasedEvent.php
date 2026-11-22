<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

/**
 * A production order was released: its operations are visible to the MES Core.
 */
final class MesWorkOrderReleasedEvent extends MesNotificationEvent
{
    /**
     * Name of the event in the outbox.
     */
    public function getEventName(): string
    {
        return 'mfg.mes.work_order.released';
    }
}
