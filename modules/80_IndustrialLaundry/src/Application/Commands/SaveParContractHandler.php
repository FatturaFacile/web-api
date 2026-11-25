<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Commands;

use Modules\IndustrialLaundry\Models\ParContract;
use Modules\IndustrialLaundry\Services\ParContractService;

final class SaveParContractHandler
{
    public function __construct(private readonly ParContractService $contracts) {}

    public function handle(SaveParContractCommand $command): ParContract
    {
        $contract = $command->contractId === null ? null : ParContract::query()->findOrFail($command->contractId);

        return $this->contracts->save($command->tenantId, $command->data, $command->userId, $contract);
    }
}
