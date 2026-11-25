<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Commands;

use Modules\GdoTradeTerms\Models\GdoSettlement;
use Modules\GdoTradeTerms\Services\SettlementService;

final class CancelSettlementHandler
{
    public function __construct(private readonly SettlementService $settlements) {}

    public function handle(CancelSettlementCommand $command): GdoSettlement
    {
        return $this->settlements->cancel(GdoSettlement::query()->findOrFail($command->settlementId), $command->userId);
    }
}
