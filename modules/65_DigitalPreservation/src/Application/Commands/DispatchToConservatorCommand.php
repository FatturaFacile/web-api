<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Application\Commands;

/**
 * Send a time-stamped batch to the configured conservator and record the RdV.
 */
final readonly class DispatchToConservatorCommand
{
    public function __construct(public string $batchId) {}
}
