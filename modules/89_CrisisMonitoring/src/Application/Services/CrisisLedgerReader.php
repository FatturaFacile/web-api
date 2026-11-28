<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Application\Services;

use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Enums\ReportingClass;
use Modules\GeneralLedger\Services\LedgerBalanceReader;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Reads from the general ledger the balance sheet totals and the revenue of the last twelve months that the CNDCEC ratios and the
 * Art. 725a / 725b CO tests need: assets (current and total), liabilities (current and total) and revenue.
 */
final readonly class CrisisLedgerReader
{
    public function __construct(private LedgerBalanceReader $ledger) {}

    /**
     * @return array{total_assets: string, current_assets: string, current_liabilities: string, total_liabilities: string, revenue: string}
     */
    public function read(string $tenantId, CarbonImmutable $asOf): array
    {
        $currentAssets = '0';
        $nonCurrentAssets = '0';
        $currentLiabilities = '0';
        $nonCurrentLiabilities = '0';

        foreach ($this->ledger->cumulative($tenantId, $asOf->startOfYear(), $asOf) as $row) {
            $amount = $this->ledger->naturalAmount($row);

            match (ReportingClass::of($row)[0] ?? null) {
                ReportingClass::Cash, ReportingClass::Receivable, ReportingClass::Inventory, ReportingClass::OtherCurrentAsset => $currentAssets = Decimal::add($currentAssets, $amount),
                ReportingClass::NonCurrentAsset => $nonCurrentAssets = Decimal::add($nonCurrentAssets, $amount),
                ReportingClass::CurrentLiability => $currentLiabilities = Decimal::add($currentLiabilities, $amount),
                ReportingClass::NonCurrentLiability => $nonCurrentLiabilities = Decimal::add($nonCurrentLiabilities, $amount),
                default => null,
            };
        }

        $revenue = '0';

        foreach ($this->ledger->movements($tenantId, $asOf->subYear()->addDay(), $asOf) as $row) {
            if ($row->account_type === 'revenue') {
                $revenue = Decimal::add($revenue, $this->ledger->naturalAmount($row));
            }
        }

        return [
            'total_assets' => Decimal::round2(Decimal::add($currentAssets, $nonCurrentAssets)),
            'current_assets' => Decimal::round2($currentAssets),
            'current_liabilities' => Decimal::round2($currentLiabilities),
            'total_liabilities' => Decimal::round2(Decimal::add($currentLiabilities, $nonCurrentLiabilities)),
            'revenue' => Decimal::round2($revenue),
        ];
    }
}
