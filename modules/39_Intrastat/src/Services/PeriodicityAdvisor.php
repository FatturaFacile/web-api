<?php

declare(strict_types=1);

namespace Modules\Intrastat\Services;

use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Intrastat\Enums\IntrastatFlow;
use Modules\Intrastat\Enums\Periodicity;
use Modules\Intrastat\Enums\SupplyKind;
use Modules\Intrastat\Models\IntrastatDeclaration;
use Modules\Intrastat\Support\PeriodicityRules;

/**
 * Measures the intra-community operations of the four quarters before a given quarter, category by category, and
 * applies the thresholds of PeriodicityRules. Only the operations the collector can classify are counted; the amounts
 * left unclassified are returned so that the user can judge them.
 */
final class PeriodicityAdvisor
{
    public function __construct(private readonly IntrastatSourceCollector $collector) {}

    /**
     * @return array<string, mixed>
     */
    public function advise(int $year, int $quarter): array
    {
        $quarters = [];

        for ($back = 4; $back >= 1; $back--) {
            $index = ($year * 4) + ($quarter - 1) - $back;
            $quarters[] = ['year' => intdiv($index, 4), 'quarter' => ($index % 4) + 1];
        }

        $totals = ['sales_goods' => [], 'sales_services' => [], 'purchases_goods' => [], 'purchases_services' => []];
        $unclassified = ['sales' => '0', 'purchases' => '0'];

        foreach ($quarters as $position => $item) {
            [$from, $to] = IntrastatDeclaration::rangeOf($item['year'], Periodicity::Quarterly, $item['quarter']);

            foreach ([IntrastatFlow::Sales, IntrastatFlow::Purchases] as $flow) {
                $collected = $this->collector->collect($flow, $from, $to);
                $sums = [SupplyKind::Goods->value => '0', SupplyKind::Services->value => '0'];

                foreach ($collected['entries'] as $entry) {
                    $sums[$entry['kind']->value] = Decimal::add($sums[$entry['kind']->value], (string) ($entry['amount_eur'] ?? '0'));
                }

                foreach ($collected['unclassified'] as $notice) {
                    $unclassified[$flow->value] = Decimal::add($unclassified[$flow->value], (string) ($notice['amount'] ?? '0'));
                }

                $totals[$flow->value.'_goods'][$position] = Decimal::round2($sums['goods']);
                $totals[$flow->value.'_services'][$position] = Decimal::round2($sums['services']);
            }
        }

        $label = static fn (array $quarterRef): string => $quarterRef['year'].'-Q'.$quarterRef['quarter'];
        $withQuarters = static fn (array $amounts): array => array_combine(array_map($label, $quarters), $amounts);
        $exceeds = static fn (array $amounts, int $threshold): bool => collect($amounts)->contains(static fn (string $amount): bool => Decimal::compare($amount, (string) $threshold) > 0);
        $reaches = static fn (array $amounts, int $threshold): bool => collect($amounts)->contains(static fn (string $amount): bool => Decimal::compare($amount, (string) $threshold) >= 0);

        $goodsPurchasesThreshold = PeriodicityRules::goodsPurchasesThreshold($year);

        return [
            'year' => $year,
            'quarter' => $quarter,
            'quarters_measured' => array_map($label, $quarters),
            'sales_goods' => [
                'model' => 'INTRA-1 bis',
                'quarterly_totals' => $withQuarters($totals['sales_goods']),
                'periodicity' => $exceeds($totals['sales_goods'], PeriodicityRules::SalesQuarterlyCeiling) ? Periodicity::Monthly->value : Periodicity::Quarterly->value,
                'quarterly_ceiling' => PeriodicityRules::SalesQuarterlyCeiling,
                'statistical_data_required' => $reaches($totals['sales_goods'], PeriodicityRules::SalesStatisticalDataThreshold),
                'statistical_data_threshold' => PeriodicityRules::SalesStatisticalDataThreshold,
            ],
            'sales_services' => [
                'model' => 'INTRA-1 quater',
                'quarterly_totals' => $withQuarters($totals['sales_services']),
                'periodicity' => $exceeds($totals['sales_services'], PeriodicityRules::SalesQuarterlyCeiling) ? Periodicity::Monthly->value : Periodicity::Quarterly->value,
                'quarterly_ceiling' => PeriodicityRules::SalesQuarterlyCeiling,
            ],
            'purchases_goods' => [
                'model' => 'INTRA-2 bis',
                'quarterly_totals' => $withQuarters($totals['purchases_goods']),
                'monthly_list_required' => $reaches($totals['purchases_goods'], $goodsPurchasesThreshold),
                'monthly_threshold' => $goodsPurchasesThreshold,
                'statistical_only' => true,
            ],
            'purchases_services' => [
                'model' => 'INTRA-2 quater',
                'quarterly_totals' => $withQuarters($totals['purchases_services']),
                'monthly_list_required' => $reaches($totals['purchases_services'], PeriodicityRules::ServicesReceivedMonthlyThreshold),
                'monthly_threshold' => PeriodicityRules::ServicesReceivedMonthlyThreshold,
                'statistical_only' => true,
            ],
            'annual_flows_above_20_million' => $this->annualFlows($year - 1),
            'unclassified_totals' => array_map(Decimal::round2(...), $unclassified),
            'notes' => [
                'A quarterly filer that exceeds the ceiling during a quarter files monthly from the following month, with the lists of the months already elapsed marked by content code 8 or 9.',
                'A quarterly filer may choose monthly lists for the whole calendar year.',
            ],
        ];
    }

    /**
     * Dispatches and arrivals of goods of the previous calendar year above 20 million euro (art. 6 c. 4 DM 22/02/2010).
     *
     * @return array{year: int, dispatches: string, arrivals: string, dispatches_above: bool, arrivals_above: bool}
     */
    private function annualFlows(int $year): array
    {
        $from = IntrastatDeclaration::rangeOf($year, Periodicity::Monthly, 1)[0];
        $to = IntrastatDeclaration::rangeOf($year, Periodicity::Monthly, 12)[1];
        $sum = function (IntrastatFlow $flow) use ($from, $to): string {
            $total = '0';

            foreach ($this->collector->collect($flow, $from, $to)['entries'] as $entry) {
                if ($entry['kind'] === SupplyKind::Goods) {
                    $total = Decimal::add($total, (string) ($entry['amount_eur'] ?? '0'));
                }
            }

            return Decimal::round2($total);
        };

        $dispatches = $sum(IntrastatFlow::Sales);
        $arrivals = $sum(IntrastatFlow::Purchases);
        $threshold = (string) PeriodicityRules::AnnualFlowsForExtendedStatistics;

        return [
            'year' => $year,
            'dispatches' => $dispatches,
            'arrivals' => $arrivals,
            'dispatches_above' => Decimal::compare($dispatches, $threshold) > 0,
            'arrivals_above' => Decimal::compare($arrivals, $threshold) > 0,
        ];
    }
}
