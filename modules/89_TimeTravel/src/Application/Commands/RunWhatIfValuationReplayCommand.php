<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Application\Commands;

use Modules\TimeTravel\Domain\Enums\ValuationMethod;
use Modules\TimeTravel\Domain\ValueObjects\BiTemporalCoordinate;

final readonly class RunWhatIfValuationReplayCommand
{
    public function __construct(
        public string $tenantId,
        public BiTemporalCoordinate $coordinate,
        public ValuationMethod $method,
        public ?string $warehouseId = null,
        public ?string $itemId = null,
        public ?string $simulationName = null,
        public ?string $createdBy = null,
    ) {}
}
