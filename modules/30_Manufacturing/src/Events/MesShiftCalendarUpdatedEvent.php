<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Events;

/**
 * The shift instances of a work centre were materialised or changed.
 */
final class MesShiftCalendarUpdatedEvent extends MesNotificationEvent
{
    /**
     * Name of the event in the outbox.
     */
    public function getEventName(): string
    {
        return 'mfg.mes.shift_calendar.updated';
    }
}
