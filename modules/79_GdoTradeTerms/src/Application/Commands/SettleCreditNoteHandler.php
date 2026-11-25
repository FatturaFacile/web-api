<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Commands;

use Modules\GdoTradeTerms\Models\GdoSettlement;
use Modules\GdoTradeTerms\Services\SettlementService;

final class SettleCreditNoteHandler
{
    public function __construct(private readonly SettlementService $settlements) {}

    public function handle(SettleCreditNoteCommand $command): GdoSettlement
    {
        return $this->settlements->settleCreditNote(GdoSettlement::query()->findOrFail($command->settlementId), $command->taxRate, $command->settledOn, $command->userId);
    }
}
