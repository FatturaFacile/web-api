<?php

declare(strict_types=1);

namespace Modules\DataResidency\Domain\Events;

/** The kill-switch suspended or crypto-shredded an envelope. */
final readonly class ByokKillSwitchActivatedEvent
{
    public function __construct(
        public string $tenantId,
        public string $envelopeId,
        public string $actorId,
        public string $reason,
        public bool $permanentCryptoShred,
    ) {}
}
