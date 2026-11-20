<?php

declare(strict_types=1);

namespace Modules\Academy\Domain\Events;

final readonly class AcademyTrackCompleted extends AcademyDomainEvent
{
    public function getEventName(): string
    {
        return self::Prefix.'track.completed';
    }

    public function getAggregateType(): string
    {
        return 'academy_track';
    }
}
