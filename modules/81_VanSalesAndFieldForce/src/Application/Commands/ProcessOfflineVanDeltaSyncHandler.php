<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Application\Commands;

use Modules\VanSalesAndFieldForce\Services\InFieldVisitAndSyncService;

final readonly class ProcessOfflineVanDeltaSyncHandler
{
    public function __construct(private InFieldVisitAndSyncService $service) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(ProcessOfflineVanDeltaSyncCommand $command): array
    {
        return $this->service->syncOfflineBatch($command->tenantId, $command->payload);
    }
}
