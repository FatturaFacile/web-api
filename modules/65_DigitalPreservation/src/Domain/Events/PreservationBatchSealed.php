<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Domain\Events;

/**
 * A batch was sealed and time-stamped.
 */
final readonly class PreservationBatchSealed
{
    public function __construct(public string $tenantId, public string $batchId, public string $merkleRoot) {}
}
