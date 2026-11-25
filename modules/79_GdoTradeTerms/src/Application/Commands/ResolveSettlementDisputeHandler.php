<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Commands;

use Modules\GdoTradeTerms\Models\GdoSettlement;
use Modules\GdoTradeTerms\Services\SettlementService;

final class ResolveSettlementDisputeHandler
{
    public function __construct(private readonly SettlementService $settlements) {}

    public function handle(ResolveSettlementDisputeCommand $command): GdoSettlement
    {
        return $this->settlements->resolveDispute(GdoSettlement::query()->findOrFail($command->settlementId), $command->resolution, $command->notes, $command->userId);
    }
}
