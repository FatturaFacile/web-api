<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Contracts;

use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\VatSettlement;

interface TaxEngineReadinessServiceInterface
{
    public function calculateVatSettlement(string $tenantId, string $fiscalPeriodId, ?string $actorUserId = null): VatSettlement;

    public function postVatSettlement(string $vatSettlementId, ?string $actorUserId = null): JournalEntry;
}
