<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Commands;

use Modules\GdoTradeTerms\Models\GdoSettlement;
use Modules\GdoTradeTerms\Services\SettlementService;

final class ReconcilePromoInvoiceHandler
{
    public function __construct(private readonly SettlementService $settlements) {}

    public function handle(ReconcilePromoInvoiceCommand $command): GdoSettlement
    {
        return $this->settlements->reconcilePromoInvoice(GdoSettlement::query()->findOrFail($command->settlementId), $command->invoice, $command->userId);
    }
}
