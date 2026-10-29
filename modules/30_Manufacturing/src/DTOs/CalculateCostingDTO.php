<?php

declare(strict_types=1);

namespace Modules\Manufacturing\DTOs;

final readonly class CalculateCostingDTO
{
    public function __construct(
        public string $tenantId,
        public string $productionOrderId
    ) {}
}
