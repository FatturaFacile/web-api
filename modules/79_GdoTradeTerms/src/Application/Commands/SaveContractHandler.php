<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Commands;

use Modules\GdoTradeTerms\Models\GdoContract;
use Modules\GdoTradeTerms\Services\ContractService;

final class SaveContractHandler
{
    public function __construct(private readonly ContractService $contracts) {}

    public function handle(SaveContractCommand $command): GdoContract
    {
        return $command->contractId === null ? $this->contracts->create($command->data, $command->tenantId, $command->userId) : $this->contracts->update(GdoContract::query()->findOrFail($command->contractId), $command->data, $command->userId);
    }
}
