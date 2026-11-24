<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Application\Commands;

use Carbon\CarbonInterface;
use Modules\DigitalPreservation\Domain\Enums\DocumentCategory;
use Modules\DigitalPreservation\Domain\Enums\JurisdictionMode;

/**
 * Collect the unpreserved documents of a category and period into a new draft batch.
 */
final readonly class CreatePreservationBatchCommand
{
    public function __construct(
        public DocumentCategory $category,
        public CarbonInterface $periodFrom,
        public CarbonInterface $periodTo,
        public ?JurisdictionMode $jurisdiction = null,
    ) {}
}
