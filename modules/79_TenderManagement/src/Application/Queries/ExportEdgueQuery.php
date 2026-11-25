<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Application\Queries;

use Carbon\CarbonImmutable;

/**
 * Generates the eDGUE of a dossier as structured data and XML, or as a PDF.
 */
final readonly class ExportEdgueQuery
{
    public function __construct(
        public string $participationId,
        public string $format = 'json',
        public ?CarbonImmutable $asOf = null,
    ) {}
}
