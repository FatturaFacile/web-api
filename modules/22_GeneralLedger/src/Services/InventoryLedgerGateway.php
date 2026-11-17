<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use Illuminate\Support\Carbon;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\Inventory\Contracts\InventoryLedgerGatewayInterface;

/**
 * General Ledger implementation of the inventory valuation gateway (FatturaFacile/FatturaFacile#144):
 * fiscal period resolution, journal lookup and inventory account balances. Scoped by tenant
 * (fiscal calendars and accounts are tenant-level in this module).
 */
final class InventoryLedgerGateway implements InventoryLedgerGatewayInterface
{
    public const string DEFAULT_INVENTORY_ACCOUNT_CODE = '1200';

    public function resolvePostingPeriod(string $tenantId, ?string $companyId, string $date): ?array
    {
        $date = Carbon::parse($date)->toDateString();

        /** @var FiscalPeriod|null $covering */
        $covering = FiscalPeriod::query()
            ->where('tenant_id', $tenantId)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->orderBy('start_date')
            ->first();

        if ($covering !== null) {
            return $this->isPostable($covering)
                ? ['fiscal_period_id' => (string) $covering->id, 'posting_date' => $date]
                : null;
        }

        // No period covers the date: post into the first later open period.
        $candidates = FiscalPeriod::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'open')
            ->whereDate('start_date', '>', $date)
            ->orderBy('start_date')
            ->get();

        foreach ($candidates as $period) {
            if ($this->isPostable($period)) {
                return [
                    'fiscal_period_id' => (string) $period->id,
                    'posting_date' => Carbon::parse($period->start_date)->toDateString(),
                ];
            }
        }

        return null;
    }

    public function findJournalEntryId(string $tenantId, string $sourceDocumentType, string $sourceDocumentId): ?string
    {
        $id = JournalEntry::query()
            ->where('tenant_id', $tenantId)
            ->where('source_document_type', $sourceDocumentType)
            ->where('source_document_id', $sourceDocumentId)
            ->whereIn('status', ['posted', 'reversed'])
            ->orderByDesc('created_at')
            ->value('id');

        return $id !== null ? (string) $id : null;
    }

    public function inventoryAccountBalance(string $tenantId, ?string $companyId, string $asOf, array $additionalAccountIds = []): string
    {
        $accountIds = ChartOfAccount::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($additionalAccountIds): void {
                $query->where('account_code', self::DEFAULT_INVENTORY_ACCOUNT_CODE);
                if ($additionalAccountIds !== []) {
                    $query->orWhereIn('id', $additionalAccountIds);
                }
            })
            ->pluck('id')
            ->all();

        if ($accountIds === []) {
            return '0.00';
        }

        $asOfDate = Carbon::parse($asOf)->toDateString();

        $totals = JournalLine::query()
            ->whereIn('account_id', $accountIds)
            ->whereHas('journalEntry', static function ($query) use ($tenantId, $asOfDate): void {
                $query->where('tenant_id', $tenantId)
                    ->whereIn('status', ['posted', 'reversed'])
                    ->whereDate('posting_date', '<=', $asOfDate);
            })
            ->selectRaw('COALESCE(SUM(debit_amount), 0) AS total_debit, COALESCE(SUM(credit_amount), 0) AS total_credit')
            ->toBase()
            ->first();

        $debit = (string) ($totals->total_debit ?? '0');
        $credit = (string) ($totals->total_credit ?? '0');

        return bcadd(bcsub($this->numeric($debit), $this->numeric($credit), 4), '0', 2);
    }

    public function findFiscalYear(string $tenantId, ?string $companyId, int $year): ?array
    {
        /** @var FiscalYear|null $fiscalYear */
        $fiscalYear = FiscalYear::query()
            ->where('tenant_id', $tenantId)
            ->whereDate('start_date', '>=', sprintf('%04d-01-01', $year))
            ->whereDate('start_date', '<=', sprintf('%04d-12-31', $year))
            ->orderBy('start_date')
            ->first();

        if ($fiscalYear === null) {
            return null;
        }

        return [
            'id' => (string) $fiscalYear->id,
            'start_date' => Carbon::parse($fiscalYear->start_date)->toDateString(),
            'end_date' => Carbon::parse($fiscalYear->end_date)->toDateString(),
            'status' => (string) $fiscalYear->status,
        ];
    }

    public function isDateInClosedPeriod(string $tenantId, ?string $companyId, string $date): bool
    {
        $date = Carbon::parse($date)->toDateString();

        /** @var FiscalYear|null $fiscalYear */
        $fiscalYear = FiscalYear::query()
            ->where('tenant_id', $tenantId)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first();

        if ($fiscalYear !== null && ($fiscalYear->status === 'closed' || (bool) $fiscalYear->is_locked)) {
            return true;
        }

        /** @var FiscalPeriod|null $period */
        $period = FiscalPeriod::query()
            ->where('tenant_id', $tenantId)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first();

        return $period !== null && $period->isLockedFor('GL');
    }

    private function isPostable(FiscalPeriod $period): bool
    {
        if (! in_array($period->status, ['open', 'soft_locked'], true)) {
            return false;
        }

        if ($period->isLockedFor('GL') || $period->isLockedFor('INVENTORY')) {
            return false;
        }

        $fiscalYear = $period->fiscalYear()->first();

        return $fiscalYear === null || ($fiscalYear->status !== 'closed' && ! (bool) $fiscalYear->is_locked);
    }

    private function numeric(string $value): string
    {
        if (preg_match('/^-?\d+(\.\d+)?$/', $value) === 1) {
            return $value;
        }

        return is_numeric($value) ? number_format((float) $value, 4, '.', '') : '0';
    }
}
