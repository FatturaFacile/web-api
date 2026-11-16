<?php

declare(strict_types=1);

namespace Modules\LeaseAccounting\Services;

use Carbon\CarbonImmutable;
use Modules\LeaseAccounting\Models\LeaseContract;
use Modules\LeaseAccounting\Models\LeaseScheduleLine;
use Modules\LeaseAccounting\Support\LeaseCalculator;
use Modules\LeaseAccounting\Support\LeaseMoney;
use Modules\LeaseAccounting\Support\LeaseTerms;

/**
 * Maturity analysis of the lease liabilities for the notes to the financial statements (IFRS 16.58 and 16.53): the
 * undiscounted payments still to be made within one year, between one and five years and after five years, set
 * against the discounted amount, which is the lease liability, with its current and non-current parts and the
 * carrying amount of the right-of-use assets. Leases with an exemption are not in the liability; their remaining
 * payments are the commitments of IFRS 16.53(c). Amounts of different currencies are never added: one section per currency.
 */
final class LeaseMaturityReportService
{
    private const array Buckets = ['within_one_year', 'one_to_five_years', 'over_five_years'];

    /**
     * @return array{as_of: string, currencies: list<array{currency: string, leases: list<array<string, mixed>>, totals: array<string, string>, exempt_commitments: array<string, string>}>}
     */
    public function report(string $tenantId, CarbonImmutable $asOf, ?string $currency = null): array
    {
        $contracts = LeaseContract::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereDate('commencement_date', '<=', $asOf->toDateString())
            ->when($currency !== null, static fn ($query) => $query->where('currency', $currency))
            ->orderBy('number')
            ->get();

        $sections = [];

        foreach ($contracts as $contract) {
            $code = (string) $contract->currency;
            $sections[$code] ??= ['currency' => $code, 'leases' => [], 'totals' => $this->emptyTotals(), 'exempt_commitments' => $this->emptyBuckets()];

            if ($contract->isExempt()) {
                $sections[$code]['exempt_commitments'] = $this->sumBuckets($sections[$code]['exempt_commitments'], $this->exemptCommitments($contract, $asOf));

                continue;
            }

            $row = $this->leaseRow($contract, $asOf);
            $sections[$code]['leases'][] = $row;

            foreach ($sections[$code]['totals'] as $key => $value) {
                $sections[$code]['totals'][$key] = LeaseMoney::round(LeaseMoney::add($value, (string) $row[$key]));
            }
        }

        ksort($sections);

        return ['as_of' => $asOf->toDateString(), 'currencies' => array_values($sections)];
    }

    /**
     * @return array<string, mixed>
     */
    private function leaseRow(LeaseContract $contract, CarbonImmutable $asOf): array
    {
        $lines = $contract->currentLines()->get();
        $advance = $contract->payment_timing === 'advance';
        $buckets = $this->emptyBuckets();

        foreach ($lines as $line) {
            if (! LeaseMoney::isPositive((string) $line->payment)) {
                continue;
            }

            $due = CarbonImmutable::parse(($advance ? $line->period_start : $line->period_end)->toDateString());
            $bucket = $this->bucketOf($due, $asOf);

            if ($bucket !== null) {
                $buckets[$bucket] = LeaseMoney::round(LeaseMoney::add($buckets[$bucket], (string) $line->payment));
            }
        }

        $liability = $this->liabilityAt($lines->all(), $asOf);
        $inTwelveMonths = $this->liabilityAt($lines->all(), $asOf->addYear());
        $undiscounted = LeaseMoney::round(array_reduce(self::Buckets, static fn (string $sum, string $bucket): string => LeaseMoney::add($sum, $buckets[$bucket]), '0'));

        return [
            'contract_id' => (string) $contract->id,
            'number' => (string) $contract->number,
            'title' => (string) $contract->title,
            'lessor_name' => (string) $contract->lessor_name,
            'asset_class' => (string) $contract->asset_class,
            'accounting_standard' => (string) $contract->accounting_standard,
            'currency' => (string) $contract->currency,
            ...$buckets,
            'total_undiscounted' => $undiscounted,
            'total_discounted' => $liability,
            'unearned_interest' => LeaseMoney::round(LeaseMoney::sub($undiscounted, $liability)),
            'current_liability' => LeaseMoney::round(LeaseMoney::sub($liability, $inTwelveMonths)),
            'non_current_liability' => $inTwelveMonths,
            'rou_carrying_amount' => $this->rouAt($lines->all(), $asOf),
        ];
    }

    /**
     * The lease liability at a date: the closing balance of the last month that ended by then; before the first month
     * ended, the liability recognised at commencement.
     *
     * @param  list<LeaseScheduleLine>  $lines
     */
    private function liabilityAt(array $lines, CarbonImmutable $date): string
    {
        $balance = $lines === [] ? '0.00' : (string) $lines[0]->opening_liability;

        foreach ($lines as $line) {
            if ($line->period_end->toDateString() <= $date->toDateString()) {
                $balance = (string) $line->closing_liability;
            }
        }

        return LeaseMoney::round($balance);
    }

    /**
     * @param  list<LeaseScheduleLine>  $lines
     */
    private function rouAt(array $lines, CarbonImmutable $date): string
    {
        $balance = $lines === [] ? '0.00' : (string) $lines[0]->rou_opening;

        foreach ($lines as $line) {
            if ($line->period_end->toDateString() <= $date->toDateString()) {
                $balance = (string) $line->rou_closing;
            }
        }

        return LeaseMoney::round($balance);
    }

    /**
     * Remaining payments of a lease with an exemption, from its terms: it has no schedule.
     *
     * @return array<string, string>
     */
    private function exemptCommitments(LeaseContract $contract, CarbonImmutable $asOf): array
    {
        $buckets = $this->emptyBuckets();
        $terms = new LeaseTerms(
            paymentAmount: LeaseMoney::normalize((string) $contract->payment_amount),
            frequencyMonths: $contract->paymentFrequencyMonths(),
            timing: $contract->payment_timing,
            escalationRatePercent: $contract->escalation_type === 'fixed' ? LeaseMoney::normalize((string) $contract->escalation_rate_percent, 6) : '0',
        );

        foreach (LeaseCalculator::paymentPlan($terms, 1, $contract->effectiveTermMonths()) as $period => $payments) {
            foreach ([['advance', LeaseCalculator::periodStart($contract->commencement(), $period)], ['arrears', LeaseCalculator::periodEnd($contract->commencement(), $period)]] as [$kind, $due]) {
                $bucket = LeaseMoney::isPositive($payments[$kind]) ? $this->bucketOf($due, $asOf) : null;

                if ($bucket !== null) {
                    $buckets[$bucket] = LeaseMoney::round(LeaseMoney::add($buckets[$bucket], $payments[$kind]));
                }
            }
        }

        return $buckets;
    }

    /**
     * @return 'within_one_year'|'one_to_five_years'|'over_five_years'|null null for a payment already due
     */
    private function bucketOf(CarbonImmutable $due, CarbonImmutable $asOf): ?string
    {
        if ($due->lessThanOrEqualTo($asOf)) {
            return null;
        }

        return match (true) {
            $due->lessThanOrEqualTo($asOf->addYear()) => 'within_one_year',
            $due->lessThanOrEqualTo($asOf->addYears(5)) => 'one_to_five_years',
            default => 'over_five_years',
        };
    }

    /**
     * @return array<string, string>
     */
    private function emptyBuckets(): array
    {
        return ['within_one_year' => '0.00', 'one_to_five_years' => '0.00', 'over_five_years' => '0.00'];
    }

    /**
     * @return array<string, string>
     */
    private function emptyTotals(): array
    {
        return $this->emptyBuckets() + [
            'total_undiscounted' => '0.00',
            'total_discounted' => '0.00',
            'unearned_interest' => '0.00',
            'current_liability' => '0.00',
            'non_current_liability' => '0.00',
            'rou_carrying_amount' => '0.00',
        ];
    }

    /**
     * @param  array<string, string>  $left
     * @param  array<string, string>  $right
     * @return array<string, string>
     */
    private function sumBuckets(array $left, array $right): array
    {
        foreach ($left as $key => $value) {
            $left[$key] = LeaseMoney::round(LeaseMoney::add($value, $right[$key]));
        }

        return $left;
    }
}
