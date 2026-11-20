<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Application\Queries;

/**
 * Filtered, paginated list of preservation batches.
 */
final readonly class ListPreservationBatchesQuery
{
    public function __construct(
        public ?string $status = null,
        public ?string $jurisdiction = null,
        public ?string $documentCategory = null,
        public ?string $search = null,
        public int $perPage = 25,
    ) {}
}
