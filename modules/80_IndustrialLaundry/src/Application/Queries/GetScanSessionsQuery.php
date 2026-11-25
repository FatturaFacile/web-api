<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Queries;

final readonly class GetScanSessionsQuery
{
    public function __construct(
        public ?string $direction = null,
        public ?string $contactId = null,
        public ?string $from = null,
        public ?string $to = null,
        public ?string $rollCageBarcode = null,
        public int $perPage = 25,
    ) {}
}
