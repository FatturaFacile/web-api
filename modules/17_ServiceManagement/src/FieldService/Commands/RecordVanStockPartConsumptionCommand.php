<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\FieldService\Commands;

/**
 * Records a spare part used on site, taken from the technician's van warehouse.
 */
final readonly class RecordVanStockPartConsumptionCommand
{
    public function __construct(
        public string $workOrderId,
        public string $productId,
        public string $quantity,
        public string $unitPrice,
        public ?string $lotOrSerialId = null,
        public ?string $lotNumber = null,
        public ?string $clientUuid = null,
        public ?string $actorUserId = null,
    ) {}
}
