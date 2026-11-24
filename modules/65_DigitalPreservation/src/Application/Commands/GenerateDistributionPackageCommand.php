<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Application\Commands;

/**
 * Build the PdD of a whole batch or of one document.
 */
final readonly class GenerateDistributionPackageCommand
{
    public function __construct(public ?string $batchId = null, public ?string $itemId = null) {}
}
