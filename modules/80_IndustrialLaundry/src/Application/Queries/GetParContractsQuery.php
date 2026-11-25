<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Queries;

final readonly class GetParContractsQuery
{
    public function __construct(
        public ?string $customerId = null,
        public ?string $segment = null,
        public ?bool $active = null,
        public ?string $search = null,
        public int $perPage = 25,
    ) {}
}
