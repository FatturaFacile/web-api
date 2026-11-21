<?php

declare(strict_types=1);

namespace Modules\DataResidency\Domain\Events;

/** An outbound transfer to a non-adequate destination was blocked. */
final readonly class UnauthorizedCrossBorderTransferBlockedEvent
{
    public function __construct(
        public string $tenantId,
        public ?string $legalEntityId,
        public ?string $destinationCountryIso2,
        public string $purpose,
        public string $reason,
    ) {}
}
