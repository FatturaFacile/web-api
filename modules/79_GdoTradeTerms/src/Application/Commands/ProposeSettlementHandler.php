<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Commands;

use Illuminate\Support\Carbon;
use Modules\GdoTradeTerms\Models\GdoContract;
use Modules\GdoTradeTerms\Models\GdoSettlement;
use Modules\GdoTradeTerms\Services\SettlementService;

final class ProposeSettlementHandler
{
    public function __construct(private readonly SettlementService $settlements) {}

    public function handle(ProposeSettlementCommand $command): GdoSettlement
    {
        return $this->settlements->propose(GdoContract::query()->findOrFail($command->contractId), $command->kind, Carbon::parse($command->periodStart), Carbon::parse($command->periodEnd), $command->userId);
    }
}
