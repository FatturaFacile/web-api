<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Services;

use Illuminate\Support\Carbon;
use Modules\ExpenseManagement\Domain\Enums\AllowanceMethod;
use Modules\ExpenseManagement\Domain\Enums\DestinationType;
use Modules\ExpenseManagement\Domain\ValueObjects\PerDiemFacts;
use Modules\ExpenseManagement\Models\ExpenseReport;
use Modules\ExpenseManagement\Models\PerDiemAllowance;
use Modules\ExpenseManagement\Strategies\FiscalStrategyRegistry;
use Modules\ExpenseManagement\Support\Decimal;

/**
 * Per-diem allowances of a trip: flat-rate, mixed or analytic, with the tax-free limit and the taxable excess of the
 * jurisdiction. A trip under 8 hours earns no allowance; from 8 hours every started 24 hours is a day.
 */
final class PerDiemService
{
    public function __construct(private readonly FiscalStrategyRegistry $strategies, private readonly PolicyEvaluator $policies) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function calculate(string $jurisdiction, array $data): array
    {
        $start = Carbon::parse((string) $data['trip_start_at']);
        $end = Carbon::parse((string) $data['trip_end_at']);
        $hours = Decimal::div((string) $start->diffInMinutes($end, true), '60');
        $days = Decimal::cmp($hours, '8') < 0 ? '0.00' : Decimal::normalize((string) (int) ceil((float) $hours / 24));

        $result = $this->strategies->for($jurisdiction)->calculatePerDiem(new PerDiemFacts(
            countryCode: strtoupper((string) $data['country_code']),
            destinationType: DestinationType::from((string) $data['destination_type']),
            method: AllowanceMethod::from((string) $data['allowance_method']),
            daysCount: $days,
            ratePerDay: isset($data['allowance_rate_per_day']) && $data['allowance_rate_per_day'] !== '' ? (string) $data['allowance_rate_per_day'] : null,
            lodgingReimbursed: (bool) ($data['lodging_reimbursed'] ?? false),
            mealsReimbursed: (bool) ($data['meals_reimbursed'] ?? false),
            policyDailyLimit: $this->policies->policyFor($jurisdiction)?->per_diem_daily_limit,
        ));

        return [
            'country_code' => strtoupper((string) $data['country_code']),
            'destination_type' => $data['destination_type'],
            'allowance_method' => $data['allowance_method'],
            'trip_start_at' => $start->toIso8601String(),
            'trip_end_at' => $end->toIso8601String(),
            'duration_hours' => $hours,
            'days_count' => $days,
            'allowance_rate_per_day' => $result->ratePerDay,
            'gross_allowance_amount' => $result->grossAmount,
            'daily_tax_free_limit' => $result->dailyTaxFreeLimit,
            'tax_free_amount' => $result->taxFreeAmount,
            'taxable_excess_amount' => $result->taxableExcessAmount,
            'meals_lodging_reductions' => $result->reductions,
            'lodging_reimbursed' => (bool) ($data['lodging_reimbursed'] ?? false),
            'meals_reimbursed' => (bool) ($data['meals_reimbursed'] ?? false),
            'warnings' => $result->warnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function add(ExpenseReport $report, array $data): PerDiemAllowance
    {
        $values = $this->calculate($report->jurisdiction, $data);
        $values['trip_start_at'] = Carbon::parse((string) $data['trip_start_at']);
        $values['trip_end_at'] = Carbon::parse((string) $data['trip_end_at']);

        return PerDiemAllowance::create($values + ['tenant_id' => $report->tenant_id, 'expense_report_id' => $report->id]);
    }
}
