<?php

declare(strict_types=1);

namespace Modules\Academy\Domain\Events;

final readonly class AcademyCertificateIssued extends AcademyDomainEvent
{
    public function getEventName(): string
    {
        return self::Prefix.'certificate.issued';
    }

    public function getAggregateType(): string
    {
        return 'academy_certificate';
    }
}
