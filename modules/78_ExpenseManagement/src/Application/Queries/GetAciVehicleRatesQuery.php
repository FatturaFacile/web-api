<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Application\Queries;

final readonly class GetAciVehicleRatesQuery
{
    public function __construct(
        public ?string $source = null,
        public ?string $vehicleCode = null,
        public ?string $fuel = null,
        public ?string $vehicleClass = null,
        public ?string $search = null,
        public ?string $onDate = null,
    ) {}
}
