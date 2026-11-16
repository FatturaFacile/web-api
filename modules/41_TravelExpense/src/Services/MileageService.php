<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Services;

use Illuminate\Validation\ValidationException;
use Modules\TravelExpense\Models\TravelMileageClaim;
use Modules\TravelExpense\Models\TravelTrip;
use Modules\TravelExpense\Support\Money;

/**
 * Mileage reimbursement of the own vehicle of the employee: the kilometres (doubled for a round trip) at the rate of
 * the company for the jurisdiction of the trip, the year of the journey and the vehicle type. The rate is
 * snapshotted in the claim, so a later change of the rate table never alters it.
 */
final readonly class MileageService
{
    public function __construct(private RateService $rates, private TravelLedger $ledger) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(TravelTrip $trip, array $data): TravelMileageClaim
    {
        $this->assertTripAcceptsClaims($trip);

        $claim = TravelMileageClaim::create($this->attributes($trip, $data) + ['tenant_id' => $trip->tenant_id, 'trip_id' => $trip->id]);
        $this->ledger->record('TRAVEL_MILEAGE_CLAIMED', $trip, ['claim_id' => $claim->id, 'amount' => $claim->amount, 'currency' => $claim->currency]);

        return $claim;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(TravelTrip $trip, TravelMileageClaim $claim, array $data): TravelMileageClaim
    {
        $this->assertTripAcceptsClaims($trip);
        $claim->update($this->attributes($trip, $data));

        return $claim->refresh();
    }

    public function delete(TravelTrip $trip, TravelMileageClaim $claim): void
    {
        $this->assertTripAcceptsClaims($trip);
        $claim->delete();
    }

    /**
     * The reimbursement of a distance at a rate: kilometres times rate, rounded half up to the cent.
     */
    public function amountOf(string $distanceKm, string $ratePerKm, bool $roundTrip): string
    {
        $kilometres = $roundTrip ? bcmul($distanceKm, '2', 2) : bcadd($distanceKm, '0', 2);

        return Money::multiply($kilometres, $ratePerKm);
    }

    private function assertTripAcceptsClaims(TravelTrip $trip): void
    {
        if (! $trip->isWorking()) {
            throw ValidationException::withMessages(['status' => __('travel_errors.trip.not_editable')]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(TravelTrip $trip, array $data): array
    {
        $date = (string) $data['travel_date'];

        if ($date < $trip->start_date->toDateString() || $date > $trip->end_date->toDateString()) {
            throw ValidationException::withMessages(['travel_date' => __('travel_errors.mileage.date_outside_trip')]);
        }

        $rate = $this->rates->findMileageRate((string) $trip->tenant_id, $trip->rate_jurisdiction, (int) substr($date, 0, 4), (string) $data['vehicle_type']);

        if ($rate === null) {
            throw ValidationException::withMessages(['vehicle_type' => __('travel_errors.mileage.rate_missing', ['jurisdiction' => $trip->rate_jurisdiction, 'year' => substr($date, 0, 4)])]);
        }

        if ($rate->currency !== $trip->currency) {
            throw ValidationException::withMessages(['currency' => __('travel_errors.mileage.currency_mismatch', ['rate' => $rate->currency, 'trip' => $trip->currency])]);
        }

        $roundTrip = (bool) ($data['round_trip'] ?? false);
        $distance = Money::normalize((string) $data['distance_km']);

        return [
            'travel_date' => $date,
            'from_location' => $data['from_location'],
            'to_location' => $data['to_location'],
            'vehicle_type' => $data['vehicle_type'],
            'round_trip' => $roundTrip,
            'distance_km' => $distance,
            'rate_per_km' => (string) $rate->rate_per_km,
            'currency' => $rate->currency,
            'amount' => $this->amountOf($distance, (string) $rate->rate_per_km, $roundTrip),
            'notes' => $data['notes'] ?? null,
        ];
    }
}
