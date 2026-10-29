<?php

declare(strict_types=1);

namespace Modules\Manufacturing\DTOs;

final readonly class RecordShopFloorExecutionDTO
{
    /**
     * @param array<int, array{
     *     operator_id: string,
     *     start_time: string,
     *     end_time: string,
     *     duration_minutes: float,
     *     hourly_rate: float
     * }> $laborLogs
     * @param array<int, array{
     *     machine_id: string,
     *     start_time: string,
     *     end_time: string,
     *     run_minutes: float,
     *     downtime_minutes: float,
     *     hourly_rate: float
     * }> $machineLogs
     */
    public function __construct(
        public string $tenantId,
        public string $productionOrderId,
        public string $productionOrderOperationId,
        public string $workCenterId,
        public ?string $operatorId = null,
        public ?string $machineId = null,
        public float $goodUnits = 0.0,
        public float $scrapUnits = 0.0,
        public ?string $downtimeReason = null,
        public array $laborLogs = [],
        public array $machineLogs = []
    ) {}
}
