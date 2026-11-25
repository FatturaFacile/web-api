<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Commands;

use Modules\GdoTradeTerms\Models\GdoDeliveryPoint;
use Modules\GdoTradeTerms\Services\DeliveryPointService;

final class SaveDeliveryPointHandler
{
    public function __construct(private readonly DeliveryPointService $points) {}

    public function handle(SaveDeliveryPointCommand $command): GdoDeliveryPoint
    {
        return $this->points->save($command->data, $command->tenantId, $command->userId, $command->deliveryPointId);
    }
}
