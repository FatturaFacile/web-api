<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Services\LedgerBalanceReader;

/**
 * Reads the post-adjustment balances of the statutory ledger for a fiscal year: the balance sheet accounts cumulated to the
 * year end and the income accounts moved during the year (closing and opening entries excluded by the ledger reader).
 */
final class LedgerSnapshotService
{
    public function __construct(private readonly LedgerBalanceReader $reader) {}

    /**
     * @return array{balance: Collection<string, object>, income: Collection<string, object>}
     */
    public function forYear(string $tenantId, FiscalYear $year): array
    {
        $from = CarbonImmutable::parse($year->start_date);
        $to = CarbonImmutable::parse($year->end_date);

        return [
            'balance' => $this->reader->cumulative($tenantId, $from, $to),
            'income' => $this->reader->movements($tenantId, $from, $to),
        ];
    }

    /**
     * The balances at the start of the year: the balance sheet at the end of the previous year, with the result of that year
     * still outside the equity accounts (it is read from the income accounts of the previous year).
     *
     * @return array{balance: Collection<string, object>, income: Collection<string, object>}
     */
    public function opening(string $tenantId, FiscalYear $year, ?FiscalYear $prior): array
    {
        $start = CarbonImmutable::parse($year->start_date);
        $openingDate = $start->subDay();
        $priorStart = $prior !== null ? CarbonImmutable::parse($prior->start_date) : $start->subYear();

        return [
            'balance' => $this->reader->cumulative($tenantId, $priorStart, $openingDate),
            'income' => $this->reader->movements($tenantId, $priorStart, $openingDate),
        ];
    }

    /**
     * The fiscal year of the tenant that ends the day before the given one starts.
     */
    public function previousYear(FiscalYear $year): ?FiscalYear
    {
        $end = CarbonImmutable::parse($year->start_date)->subDay()->toDateString();

        return FiscalYear::query()->whereDate('end_date', $end)->where('id', '!=', $year->id)->first();
    }
}
