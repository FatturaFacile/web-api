<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use Enterprise\MultiTenant\Scopes\TenantScope;
use Modules\GeneralLedger\Contracts\TaxEngineReadinessServiceInterface;
use Modules\GeneralLedger\Contracts\VatSettlementServiceInterface;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\VatSettlement;

/**
 * Entry point of the VAT settlement by fiscal period, kept for its existing callers.
 */
final class TaxEngineReadinessService implements TaxEngineReadinessServiceInterface
{
    public function __construct(
        private readonly VatSettlementServiceInterface $vatSettlementService
    ) {}

    public function calculateVatSettlement(string $tenantId, string $fiscalPeriodId, ?string $actorUserId = null): VatSettlement
    {
        return $this->vatSettlementService->calculateForFiscalPeriod($tenantId, $fiscalPeriodId, $actorUserId);
    }

    public function postVatSettlement(string $vatSettlementId, ?string $actorUserId = null): ?JournalEntry
    {
        $settlement = VatSettlement::withoutGlobalScope(TenantScope::class)->findOrFail($vatSettlementId);

        return $this->vatSettlementService->post((string) $settlement->tenant_id, $vatSettlementId, $actorUserId);
    }
}
