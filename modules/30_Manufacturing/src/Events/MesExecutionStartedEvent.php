<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

/**
 * A shop floor job card was started: it tells the MES Core which order runs on the machine.
 */
final class MesExecutionStartedEvent extends MesNotificationEvent
{
    /**
     * Name of the event in the outbox.
     */
    public function getEventName(): string
    {
        return 'mfg.mes.execution.started';
    }
}
