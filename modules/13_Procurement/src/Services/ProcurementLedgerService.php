<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use Illuminate\Support\Str;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\Procurement\Support\Decimal;

/**
 * Journal entries of procurement documents through the posting service of the general ledger, on the accounts the
 * goods receipt accrual already uses (1200 inventory, 2010 GR/IR clearing): the reversal of the accrual when goods go
 * back to the supplier, and the capitalization of landed costs against their clearing account.
 */
final readonly class ProcurementLedgerService
{
    public function __construct(
        private DoubleEntryPostingServiceInterface $posting,
        private FiscalPeriodResolver $periods,
    ) {}

    /**
     * Inventory leaves at the value it was received at, and the GR/IR clearing accrued at receipt is reduced.
     */
    public function postSupplierReturn(string $tenantId, ?string $companyId, string $returnId, string $returnNumber, string $date, string $value): ?JournalEntry
    {
        if (! Decimal::isPositive($value)) {
            return null;
        }

        $inventory = $this->account($tenantId, '1200', 'Merchandise Inventory Asset', 'asset', 'debit');
        $grIr = $this->account($tenantId, '2010', 'GR/IR Clearing Account', 'liability', 'credit');

        return $this->post($tenantId, $companyId, $date, 'RTV-'.$returnNumber, 'purchase_return', $returnId, "Return to supplier {$returnNumber}", [
            ['account_id' => $grIr->id, 'debit_amount' => (float) $value, 'credit_amount' => 0.0, 'description' => 'GR/IR clearing reduced by the return'],
            ['account_id' => $inventory->id, 'debit_amount' => 0.0, 'credit_amount' => (float) $value, 'description' => 'Inventory returned to supplier'],
        ]);
    }

    /**
     * The landed cost raises the inventory value; the clearing account is cleared by the invoice of the carrier or the
     * customs broker.
     */
    public function postLandedCost(string $tenantId, ?string $companyId, string $landedCostId, string $number, string $date, string $amount, string $clearingAccountCode): JournalEntry
    {
        $inventory = $this->account($tenantId, '1200', 'Merchandise Inventory Asset', 'asset', 'debit');
        $clearing = $this->account($tenantId, $clearingAccountCode, 'Landed costs clearing', 'liability', 'credit');

        return $this->post($tenantId, $companyId, $date, $number, 'landed_cost', $landedCostId, "Landed cost {$number}", [
            ['account_id' => $inventory->id, 'debit_amount' => (float) $amount, 'credit_amount' => 0.0, 'description' => 'Landed cost capitalized into inventory'],
            ['account_id' => $clearing->id, 'debit_amount' => 0.0, 'credit_amount' => (float) $amount, 'description' => 'Landed cost to be invoiced'],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function post(string $tenantId, ?string $companyId, string $date, string $entryNumber, string $sourceType, string $sourceId, string $description, array $lines): JournalEntry
    {
        $period = $this->periods->forDate($tenantId, $date);

        return $this->posting->createAndPost([
            'tenant_id' => $tenantId,
            'company_id' => $companyId ?? $tenantId,
            'fiscal_period_id' => $period->id,
            'posting_date' => $date,
            'document_date' => $date,
            'entry_number' => $entryNumber,
            'source_document_type' => $sourceType,
            'source_document_id' => $sourceId,
            'journal_type' => 'standard',
            'description' => $description,
            'lines' => $lines,
        ]);
    }

    private function account(string $tenantId, string $code, string $name, string $type, string $normalBalance): ChartOfAccount
    {
        return ChartOfAccount::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenantId, 'account_code' => $code],
            [
                'id' => (string) Str::uuid7(),
                'name' => $name,
                'account_class' => $type,
                'account_type' => $type,
                'normal_balance' => $normalBalance,
                'currency' => TenantAccountingCurrency::of($tenantId),
                'is_active' => true,
            ]
        );
    }
}
