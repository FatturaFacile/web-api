<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Actions;

use Modules\GeneralLedger\Contracts\TaxEngineReadinessServiceInterface;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\VatSettlement;

final readonly class ProcessVatSettlementAction
{
    public function __construct(
        private TaxEngineReadinessServiceInterface $taxService
    ) {}

    public function calculate(string $tenantId, string $fiscalPeriodId, ?string $actorUserId = null): VatSettlement
    {
        return $this->taxService->calculateVatSettlement($tenantId, $fiscalPeriodId, $actorUserId);
    }

    public function post(string $vatSettlementId, ?string $actorUserId = null): JournalEntry
    {
        return $this->taxService->postVatSettlement($vatSettlementId, $actorUserId);
    }
}
