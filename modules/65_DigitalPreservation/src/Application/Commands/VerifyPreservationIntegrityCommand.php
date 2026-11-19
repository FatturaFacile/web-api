<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Application\Commands;

/**
 * Live cryptographic re-verification of a batch, or of a single document when an item id is given.
 */
final readonly class VerifyPreservationIntegrityCommand
{
    public function __construct(public ?string $batchId = null, public ?string $itemId = null) {}
}
