<?php

declare(strict_types=1);

namespace Modules\SwissVat\Application\Commands;

use Modules\SwissVat\Models\SwissVatConfig;
use Modules\SwissVat\Services\SwissVatConfigService;

final readonly class UpsertSwissVatConfigHandler
{
    public function __construct(private SwissVatConfigService $configService) {}

    public function handle(UpsertSwissVatConfigCommand $command): SwissVatConfig
    {
        return $this->configService->upsert($command->tenantId, $command->actorUserId, $command->attributes);
    }
}
