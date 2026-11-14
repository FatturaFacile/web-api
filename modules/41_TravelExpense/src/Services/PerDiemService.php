<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\TravelExpense\Models\TravelPerDiemDay;
use Modules\TravelExpense\Models\TravelPerDiemRate;
use Modules\TravelExpense\Models\TravelTrip;
use Modules\TravelExpense\Support\Money;

/**
 * Calculates and stores the per-diem days of a trip from the rate tables of the company. Calculating again replaces the
 * days; the meals and lodging provided by the employer are kept per day unless the caller sends them.
 */
final readonly class PerDiemService
{
    public function __construct(private PerDiemCalculator $calculator, private RateService $rates, private TravelLedger $ledger) {}

    /**
     * The scope of the allowance: domestic when the destination is the country of the jurisdiction.
     */
    public function scopeOf(TravelTrip $trip): string
    {
        return $trip->destination_country === $trip->rate_jurisdiction ? TravelPerDiemRate::Domestic : TravelPerDiemRate::Abroad;
    }

    /**
     * @param  array<string, array<string, bool>>|null  $providedByDay  Y-m-d => meals and lodging provided; null keeps the stored ones
     * @return Collection<int, TravelPerDiemDay>
     */
    public function calculate(TravelTrip $trip, ?array $providedByDay = null): Collection
    {
        if (! $trip->isWorking()) {
            throw ValidationException::withMessages(['status' => __('travel_errors.trip.not_editable')]);
        }

        $tenantId = (string) $trip->tenant_id;
        $hoursPerDay = $this->calculator->hoursPerDay($trip);
        $scope = $this->scopeOf($trip);

        if ($providedByDay !== null) {
            $unknown = array_diff(array_keys($providedByDay), array_keys($hoursPerDay));

            if ($unknown !== []) {
                throw ValidationException::withMessages(['days' => __('travel_errors.per_diem.day_outside_trip')]);
            }
        }

        $stored = $trip->perDiemDays()->get()->keyBy(static fn (TravelPerDiemDay $day): string => $day->day->toDateString());

        return DB::transaction(function () use ($trip, $tenantId, $hoursPerDay, $scope, $providedByDay, $stored): Collection {
            TravelPerDiemDay::query()->where('trip_id', $trip->id)->forceDelete();

            $total = '0.00';
            $days = new Collection;

            foreach ($hoursPerDay as $date => $hours) {
                $rate = $this->rates->findPerDiemRate($tenantId, $trip->rate_jurisdiction, (int) substr($date, 0, 4), $scope, $trip->destination_country);

                if ($rate === null) {
                    throw ValidationException::withMessages(['rate' => __('travel_errors.per_diem.rate_missing', ['jurisdiction' => $trip->rate_jurisdiction, 'year' => substr($date, 0, 4)])]);
                }

                if ($rate->currency !== $trip->currency) {
                    throw ValidationException::withMessages(['currency' => __('travel_errors.per_diem.currency_mismatch', ['rate' => $rate->currency, 'trip' => $trip->currency])]);
                }

                $provided = $providedByDay !== null
                    ? ($providedByDay[$date] ?? [])
                    : ($stored->has($date) ? $this->flagsOf($stored->get($date)) : []);
                $line = $this->calculator->calculateDay($rate, $hours, $provided);

                $days->push(TravelPerDiemDay::create($line + [
                    'tenant_id' => $tenantId,
                    'trip_id' => $trip->id,
                    'rate_id' => $rate->id,
                    'day' => $date,
                    'breakfast_provided' => (bool) ($provided['breakfast_provided'] ?? false),
                    'lunch_provided' => (bool) ($provided['lunch_provided'] ?? false),
                    'dinner_provided' => (bool) ($provided['dinner_provided'] ?? false),
                    'lodging_provided' => (bool) ($provided['lodging_provided'] ?? false),
                    'currency' => $rate->currency,
                ]));
                $total = Money::add($total, $line['amount']);
            }

            $this->ledger->record('TRAVEL_PER_DIEM_CALCULATED', $trip, ['days' => $days->count(), 'total' => $total, 'currency' => $trip->currency]);

            return $days;
        });
    }

    /**
     * @return array{breakfast_provided: bool, lunch_provided: bool, dinner_provided: bool, lodging_provided: bool}
     */
    private function flagsOf(TravelPerDiemDay $day): array
    {
        return [
            'breakfast_provided' => $day->breakfast_provided,
            'lunch_provided' => $day->lunch_provided,
            'dinner_provided' => $day->dinner_provided,
            'lodging_provided' => $day->lodging_provided,
        ];
    }
}
