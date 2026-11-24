<?php

declare(strict_types=1);

namespace Modules\Academy\Domain\Events;

final readonly class AcademyMissionCompleted extends AcademyDomainEvent
{
    public function getEventName(): string
    {
        return self::Prefix.'mission.completed';
    }

    public function getAggregateType(): string
    {
        return 'academy_mission';
    }
}
