<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Contracts;

use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\VatSettlement;
use Modules\GeneralLedger\Services\Tax\VatPeriod;

interface VatSettlementServiceInterface
{
    /**
     * Calculates, or recalculates while still `calculated`, the settlement of a whole VAT period.
     */
    public function calculate(string $tenantId, VatPeriod $period, ?string $actorUserId = null): VatSettlement;

    /**
     * Calculates the settlement of the calendar month, quarter or half year spanned by a fiscal period.
     */
    public function calculateForFiscalPeriod(string $tenantId, string $fiscalPeriodId, ?string $actorUserId = null): VatSettlement;

    /**
     * Registers the settlement in the ledger and closes it. Returns null when the period moved no VAT.
     */
    public function post(string $tenantId, string $vatSettlementId, ?string $actorUserId = null): ?JournalEntry;

    /**
     * Records the payment of a posted settlement, registering it against the bank account when one is given.
     */
    public function markPaid(string $tenantId, string $vatSettlementId, string $paymentDate, ?string $bankAccountId = null, ?string $actorUserId = null): VatSettlement;
}
