<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Application\Queries;

/**
 * Compliance search over the preserved documents.
 */
final readonly class SearchPreservationItemsQuery
{
    public function __construct(
        public ?string $search = null,
        public ?string $documentNumber = null,
        public ?string $counterparty = null,
        public ?string $taxId = null,
        public ?string $sha256 = null,
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public ?string $documentCategory = null,
        public ?string $jurisdiction = null,
        public int $perPage = 25,
    ) {}
}
