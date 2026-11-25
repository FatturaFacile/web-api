<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Application\Queries;

final readonly class GetWeekdayReplenishmentSuggestionQuery
{
    public function __construct(
        public string $tenantId,
        public string $vanWarehouseId,
        public int $weekdayIso,
        public ?string $routeCode = null,
    ) {}
}
