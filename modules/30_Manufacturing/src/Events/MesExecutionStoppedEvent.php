<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

/**
 * A shop floor job card was completed.
 */
final class MesExecutionStoppedEvent extends MesNotificationEvent
{
    /**
     * Name of the event in the outbox.
     */
    public function getEventName(): string
    {
        return 'mfg.mes.execution.stopped';
    }
}
