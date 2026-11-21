<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

/**
 * The machine of an operation of a released production order was set or changed.
 */
final class MesWorkOrderAssignedToMachineEvent extends MesNotificationEvent
{
    /**
     * Name of the event in the outbox.
     */
    public function getEventName(): string
    {
        return 'mfg.mes.work_order.assigned_to_machine';
    }
}
