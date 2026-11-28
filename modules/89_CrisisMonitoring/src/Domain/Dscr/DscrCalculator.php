<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Domain\Dscr;

use Modules\CrisisMonitoring\Domain\Enums\TrafficLight;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * The rolling debt service coverage ratio over 6 and 12 months, in the two CNDCEC approaches:
 *
 * - treasury (approach 1): (free cash flow before debt service over the horizon + opening liquid assets) divided by the expected
 *   outflows for financial, tax, social security and overdue supplier debt;
 * - operating (approach 2): (operating cash flow after taxes + available credit lines) divided by the financial debt service.
 *
 * Pure: it works on the monthly buckets and never reads a record.
 */
final readonly class DscrCalculator
{
    public const array Horizons = [6, 12];

    public function __construct(private DscrBands $bands = new DscrBands) {}

    /**
     * @param  list<MonthlyBucket>  $buckets  twelve consecutive months, the first one being the month of the snapshot
     * @return array{months: list<array<string, mixed>>, dscr: array{treasury: array<string, array<string, mixed>>, operating: array<string, array<string, mixed>>}}
     */
    public function project(array $buckets, string $openingCash, string $creditLines): array
    {
        $months = [];
        $cash = $openingCash;

        foreach ($buckets as $index => $bucket) {
            $debtService = $this->debtService($bucket);
            $fcf = $this->freeCashFlow($bucket);
            $cash = Decimal::add($cash, Decimal::sub($fcf, $debtService));
            $window = array_slice($buckets, 0, $index + 1);

            $months[] = [
                'month' => $bucket->month->startOfMonth()->toDateString(),
                'operating_inflows' => Decimal::round2($bucket->inflows),
                'operating_outflows' => Decimal::round2($bucket->operatingOutflows),
                'debt_service' => Decimal::round2($debtService),
                'tax_social' => Decimal::round2($bucket->taxSocial),
                'capex' => Decimal::round2($bucket->capex),
                'free_cash_flow' => Decimal::round2($fcf),
                'closing_cash' => Decimal::round2($cash),
                'cumulative_cash_with_credit' => Decimal::round2(Decimal::add($cash, $creditLines)),
                'dscr_treasury' => $this->treasury($window, $openingCash)['value'],
                'dscr_operating' => $this->operating($window, $creditLines)['value'],
            ];
        }

        $result = ['treasury' => [], 'operating' => []];

        foreach (self::Horizons as $horizon) {
            $window = array_slice($buckets, 0, $horizon);
            $result['treasury']['m'.$horizon] = $this->treasury($window, $openingCash);
            $result['operating']['m'.$horizon] = $this->operating($window, $creditLines);
        }

        return ['months' => $months, 'dscr' => $result];
    }

    /**
     * Approach 1 over a window of months.
     *
     * @param  list<MonthlyBucket>  $window
     * @return array{value: string|null, status: string, applicable: bool, numerator: string, denominator: string, label_key: string}
     */
    public function treasury(array $window, string $openingCash): array
    {
        $fcf = '0';
        $service = '0';

        foreach ($window as $bucket) {
            $fcf = Decimal::add($fcf, $this->freeCashFlow($bucket));
            $service = Decimal::add($service, $this->debtService($bucket));
        }

        return $this->ratio(Decimal::add($fcf, $openingCash), $service, 'crisisApi.dscr.treasury');
    }

    /**
     * Approach 2 over a window of months.
     *
     * @param  list<MonthlyBucket>  $window
     * @return array{value: string|null, status: string, applicable: bool, numerator: string, denominator: string, label_key: string}
     */
    public function operating(array $window, string $creditLines): array
    {
        $operating = '0';
        $service = '0';

        foreach ($window as $bucket) {
            $operating = Decimal::add($operating, Decimal::sub(Decimal::sub($bucket->inflows, $bucket->operatingOutflows), $bucket->taxSocial));
            $service = Decimal::add($service, $bucket->financialDebt);
        }

        return $this->ratio(Decimal::add($operating, $creditLines), $service, 'crisisApi.dscr.operating');
    }

    /**
     * Free cash flow available for debt service: receipts less ordinary outflows and capex.
     */
    public function freeCashFlow(MonthlyBucket $bucket): string
    {
        return Decimal::sub(Decimal::sub($bucket->inflows, $bucket->operatingOutflows), $bucket->capex);
    }

    /**
     * The outflows of approach 1: financial debt, tax and social security, overdue suppliers.
     */
    public function debtService(MonthlyBucket $bucket): string
    {
        return Decimal::add(Decimal::add($bucket->financialDebt, $bucket->taxSocial), $bucket->overdueSuppliers);
    }

    /**
     * @return array{value: string|null, status: string, applicable: bool, numerator: string, denominator: string, label_key: string}
     */
    private function ratio(string $numerator, string $denominator, string $labelKey): array
    {
        if (! Decimal::isPositive($denominator)) {
            return ['value' => null, 'status' => TrafficLight::Green->value, 'applicable' => false, 'numerator' => Decimal::round2($numerator), 'denominator' => Decimal::round2($denominator), 'label_key' => $labelKey];
        }

        $value = Decimal::div($numerator, $denominator);

        return [
            'value' => Decimal::round2($value),
            'status' => $this->bands->classify($value)->value,
            'applicable' => true,
            'numerator' => Decimal::round2($numerator),
            'denominator' => Decimal::round2($denominator),
            'label_key' => $labelKey,
        ];
    }
}
