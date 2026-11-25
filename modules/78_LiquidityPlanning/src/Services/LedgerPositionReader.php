<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Services;

use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Enums\ReportingClass;
use Modules\GeneralLedger\Services\LedgerBalanceReader;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Reads from the general ledger the figures the crisis indicators need: net equity (equity accounts plus the result of the
 * year to date), financial debt (non-current liabilities) and the EBITDA of the last twelve months (revenue less cost of
 * sales and operating expenses, depreciation and other non-cash expenses excluded).
 */
final readonly class LedgerPositionReader
{
    public function __construct(private LedgerBalanceReader $ledger) {}

    /**
     * @return array{net_equity: string, financial_debt: string, ebitda: string}
     */
    public function read(string $tenantId, CarbonImmutable $asOf): array
    {
        $yearStart = $asOf->startOfYear();
        $equity = '0';
        $debt = '0';

        foreach ($this->ledger->cumulative($tenantId, $yearStart, $asOf) as $row) {
            $class = ReportingClass::of($row)[0] ?? null;
            $amount = $this->ledger->naturalAmount($row);

            if ($class === ReportingClass::Equity) {
                $equity = Decimal::add($equity, $amount);
            } elseif ($class === ReportingClass::NonCurrentLiability) {
                $debt = Decimal::add($debt, $amount);
            }
        }

        $equity = Decimal::add($equity, $this->result($tenantId, $yearStart, $asOf));

        return [
            'net_equity' => Decimal::round2($equity),
            'financial_debt' => Decimal::round2($debt),
            'ebitda' => $this->ebitda($tenantId, $asOf->subYear()->addDay(), $asOf),
        ];
    }

    private function result(string $tenantId, CarbonImmutable $from, CarbonImmutable $to): string
    {
        $result = '0';

        foreach ($this->ledger->movements($tenantId, $from, $to) as $row) {
            $amount = $this->ledger->naturalAmount($row);
            $result = match ($row->account_type) {
                'revenue' => Decimal::add($result, $amount),
                'cogs', 'expense' => Decimal::sub($result, $amount),
                default => $result,
            };
        }

        return $result;
    }

    private function ebitda(string $tenantId, CarbonImmutable $from, CarbonImmutable $to): string
    {
        $ebitda = '0';

        foreach ($this->ledger->movements($tenantId, $from, $to) as $row) {
            $amount = $this->ledger->naturalAmount($row);
            $nonCash = (ReportingClass::of($row)[0] ?? null) === ReportingClass::NonCashExpense;
            $ebitda = match ($row->account_type) {
                'revenue' => Decimal::add($ebitda, $amount),
                'cogs' => Decimal::sub($ebitda, $amount),
                'expense' => $nonCash ? $ebitda : Decimal::sub($ebitda, $amount),
                default => $ebitda,
            };
        }

        return Decimal::round2($ebitda);
    }
}
