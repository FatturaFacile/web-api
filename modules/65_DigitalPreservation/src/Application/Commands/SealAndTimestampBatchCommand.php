<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Application\Commands;

/**
 * Generate the PdV.xml, compute the Merkle root and apply the RFC 3161 time stamp.
 */
final readonly class SealAndTimestampBatchCommand
{
    public function __construct(public string $batchId, public ?string $actorId = null) {}
}
