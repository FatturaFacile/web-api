<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Queries;

final readonly class GetParStockDashboardQuery
{
    public function __construct(
        public ?string $segment = null,
        public ?string $customerId = null,
        public ?string $asOf = null,
        public bool $withPreview = true,
    ) {}
}
