<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Commands;

use Modules\GdoTradeTerms\Models\GdoContract;
use Modules\GdoTradeTerms\Services\ContractService;

final class TransitionContractHandler
{
    public function __construct(private readonly ContractService $contracts) {}

    public function handle(TransitionContractCommand $command): GdoContract
    {
        return $this->contracts->transition(GdoContract::query()->findOrFail($command->contractId), $command->action, $command->userId);
    }
}
