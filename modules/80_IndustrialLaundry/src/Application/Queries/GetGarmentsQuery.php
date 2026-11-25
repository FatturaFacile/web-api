<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Queries;

final readonly class GetGarmentsQuery
{
    public function __construct(
        public ?string $state = null,
        public ?string $articleType = null,
        public ?string $customerId = null,
        public ?string $search = null,
        public int $perPage = 25,
    ) {}
}
