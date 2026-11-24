<?php

declare(strict_types=1);

namespace Modules\DataResidency\Domain\Events;

/** The key-encryption key of an envelope was rotated. */
final readonly class ByokKeyRotatedEvent
{
    public function __construct(
        public string $tenantId,
        public string $envelopeId,
        public string $oldKeyVersion,
        public string $newKeyVersion,
    ) {}
}
