<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Services;

use Modules\TimeTravel\Domain\Enums\AggregateType;
use Modules\TimeTravel\Services\Projections\FixedAssetsProjector;
use Modules\TimeTravel\Services\Projections\GeneralLedgerProjector;
use Modules\TimeTravel\Services\Projections\InventoryProjector;
use Modules\TimeTravel\Services\Projections\OpenItemsProjector;
use Modules\TimeTravel\Services\Projections\StateProjector;

final class ProjectorRegistry
{
    public function for(AggregateType $type): StateProjector
    {
        return match ($type) {
            AggregateType::GeneralLedger => new GeneralLedgerProjector,
            AggregateType::OpenItems => new OpenItemsProjector,
            AggregateType::InventoryWms => new InventoryProjector,
            AggregateType::FixedAssets => new FixedAssetsProjector,
        };
    }
}
