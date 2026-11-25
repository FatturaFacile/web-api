<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Services;

use Illuminate\Support\Carbon;
use Modules\ExpenseManagement\Domain\Enums\CalculationSource;
use Modules\ExpenseManagement\Domain\Enums\PaymentMethod;
use Modules\ExpenseManagement\Domain\ValueObjects\MileageRateRequest;
use Modules\ExpenseManagement\Models\ExpenseLine;
use Modules\ExpenseManagement\Models\ExpenseReport;
use Modules\ExpenseManagement\Models\MileageClaim;
use Modules\ExpenseManagement\Strategies\FiscalStrategyRegistry;
use Modules\ExpenseManagement\Support\Decimal;

/**
 * Mileage reimbursement: the rate of the jurisdiction (CH TCS / regulation, IT ACI table), the distance (doubled on a
 * round trip) and the expense line that carries the amount.
 */
final class MileageService
{
    public function __construct(
        private readonly FiscalStrategyRegistry $strategies,
        private readonly PolicyEvaluator $policies,
        private readonly ExpenseLineService $lines,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{rate_per_km: string, total_km: string, calculated_amount: string, calculation_source: string, rate_effective_date: string|null, rate_period: string|null, currency: string}
     */
    public function quote(string $jurisdiction, array $data): array
    {
        $policy = $this->policies->policyFor($jurisdiction);
        $rate = $this->strategies->for($jurisdiction)->resolveMileageRate(new MileageRateRequest(
            vehicleClassCode: (string) $data['vehicle_class_code'],
            onDate: Carbon::parse((string) ($data['expense_date'] ?? $data['on_date'] ?? now()->toDateString())),
            annualKmBracket: isset($data['annual_km_bracket']) ? (int) $data['annual_km_bracket'] : null,
            requestedSource: isset($data['calculation_source']) ? CalculationSource::from((string) $data['calculation_source']) : null,
            policyRatePerKm: $policy?->mileage_rate_per_km,
        ));

        $distance = Decimal::normalize($data['distance_km']);
        $totalKm = ! empty($data['is_round_trip']) ? Decimal::mul($distance, '2') : $distance;

        return [
            'rate_per_km' => $rate->ratePerKm,
            'total_km' => $totalKm,
            'calculated_amount' => Decimal::mul($totalKm, $rate->ratePerKm),
            'calculation_source' => $rate->source->value,
            'rate_effective_date' => $rate->effectiveDate,
            'rate_period' => $rate->ratePeriod,
            'currency' => $rate->currency,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function add(ExpenseReport $report, array $data): ExpenseLine
    {
        $quote = $this->quote($report->jurisdiction, $data);
        $category = $this->lines->categoryByCode('mileage');
        $merchant = mb_substr($data['origin_address'].' → '.$data['destination_address'], 0, 200);

        $line = $this->lines->save($report, null, [
            'expense_date' => $data['expense_date'],
            'category_id' => $category->id,
            'merchant_name' => $merchant,
            'description' => $data['description'] ?? null,
            'original_amount' => $quote['calculated_amount'],
            'original_currency' => $report->currency,
            'payment_method' => PaymentMethod::EmployeeOutOfPocket->value,
            'project_id' => $data['project_id'] ?? null,
            'billable_to_customer' => $data['billable_to_customer'] ?? false,
        ]);

        MileageClaim::create([
            'tenant_id' => $report->tenant_id,
            'expense_line_id' => $line->id,
            'origin_address' => $data['origin_address'],
            'destination_address' => $data['destination_address'],
            'waypoint_addresses' => $data['waypoint_addresses'] ?? null,
            'distance_km' => Decimal::normalize($data['distance_km']),
            'is_round_trip' => (bool) ($data['is_round_trip'] ?? false),
            'vehicle_id' => $data['vehicle_id'] ?? null,
            'vehicle_make_model' => $data['vehicle_make_model'] ?? null,
            'vehicle_class_code' => $data['vehicle_class_code'],
            'rate_per_km' => $quote['rate_per_km'],
            'calculated_amount' => $quote['calculated_amount'],
            'calculation_source' => $quote['calculation_source'],
            'rate_period' => $quote['rate_period'],
            'rate_effective_date' => $quote['rate_effective_date'],
        ]);

        return $line->load('category', 'mileageClaim');
    }
}
