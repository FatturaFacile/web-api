<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Domain\Events;

/**
 * A sealed batch was handed to a conservator.
 */
final readonly class PreservationPackageDispatched
{
    public function __construct(public string $tenantId, public string $batchId, public string $provider, public ?string $externalId) {}
}
