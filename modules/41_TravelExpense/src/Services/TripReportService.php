<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Services;

use Modules\TravelExpense\Models\TravelTrip;
use Modules\TravelExpense\Support\Money;

/**
 * The report of a trip: plan against actual. The plan is the estimated budget and the cost booked on the itinerary; the
 * actual is what the company owes for the trip (per diem, mileage, approved expenses). Once the trip is reconciled the
 * frozen figures are reported, before that the live ones.
 */
final readonly class TripReportService
{
    public function __construct(private ReconciliationService $reconciliation, private ItineraryService $itinerary) {}

    /**
     * @return array{
     *     budget: array{currency: string, estimated: string, booked_itinerary: string, booked_by_type: array<string, string>, actual: string, variance: string, utilization_percent: string|null, within_budget: bool},
     *     reconciliation: array<string, mixed>,
     *     frozen: bool
     * }
     */
    public function build(TravelTrip $trip): array
    {
        $summary = $this->reconciliation->summarize($trip);
        $frozen = $trip->status === TravelTrip::Reconciled;

        if ($frozen) {
            $summary['per_diem_total'] = Money::normalize((string) $trip->reconciled_per_diem_total);
            $summary['mileage_total'] = Money::normalize((string) $trip->reconciled_mileage_total);
            $summary['expenses_total'] = Money::normalize((string) $trip->reconciled_expenses_total);
            $summary['total_due'] = Money::add(Money::add($summary['per_diem_total'], $summary['mileage_total']), $summary['expenses_total']);
            $summary['advance_paid'] = Money::normalize((string) $trip->reconciled_advance_total);
            $summary['balance'] = Money::normalize((string) $trip->reconciled_balance);
            $summary['balance_amount'] = Money::abs($summary['balance']);
            $summary['direction'] = (string) $trip->reconciled_direction;
        }

        $booked = $this->itinerary->bookedCosts($trip);
        $estimated = Money::normalize((string) $trip->estimated_budget);
        $actual = $summary['total_due'];

        return [
            'budget' => [
                'currency' => $trip->currency,
                'estimated' => $estimated,
                'booked_itinerary' => $booked['total'],
                'booked_by_type' => $booked['by_type'],
                'actual' => $actual,
                'variance' => Money::sub($actual, $estimated),
                'utilization_percent' => Money::compare($estimated, '0') > 0 ? bcdiv(bcmul($actual, '100', 4), $estimated, 2) : null,
                'within_budget' => Money::compare($actual, $estimated) <= 0,
            ],
            'reconciliation' => $summary,
            'frozen' => $frozen,
        ];
    }
}
