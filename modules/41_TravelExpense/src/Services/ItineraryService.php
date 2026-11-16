<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\TravelExpense\Models\TravelLeg;
use Modules\TravelExpense\Models\TravelTrip;
use Modules\TravelExpense\Support\Money;

/**
 * The itinerary of a trip: ordered legs (flight, train, car, hotel) with their booked cost. Cancelled legs stay on the
 * record but cost nothing. The booked cost is a budget figure: what the employee pays and claims goes through the
 * linked expenses.
 */
final readonly class ItineraryService
{
    public function __construct(private TravelLedger $ledger) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function addLeg(TravelTrip $trip, array $data): TravelLeg
    {
        $this->assertEditable($trip);
        $attributes = $this->attributes($trip, $data);

        return DB::transaction(function () use ($trip, $attributes): TravelLeg {
            $attributes['sequence'] ??= ((int) TravelLeg::query()->where('trip_id', $trip->id)->max('sequence')) + 1;
            $leg = TravelLeg::create($attributes + ['tenant_id' => $trip->tenant_id, 'trip_id' => $trip->id]);
            $this->ledger->record('TRAVEL_LEG_ADDED', $trip, ['leg_id' => $leg->id, 'leg_type' => $leg->leg_type, 'booked_cost' => $leg->booked_cost]);

            return $leg;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateLeg(TravelTrip $trip, TravelLeg $leg, array $data): TravelLeg
    {
        $this->assertEditable($trip);
        $leg->update($this->attributes($trip, $data));

        return $leg->refresh();
    }

    public function removeLeg(TravelTrip $trip, TravelLeg $leg): void
    {
        $this->assertEditable($trip);
        $leg->delete();
    }

    /**
     * Booked cost of the legs that are not cancelled, total and by leg type, in the currency of the trip.
     *
     * @return array{total: string, by_type: array<string, string>}
     */
    public function bookedCosts(TravelTrip $trip): array
    {
        $total = '0.00';
        $byType = [];

        foreach (TravelLeg::query()->where('trip_id', $trip->id)->where('booking_status', '!=', 'cancelled')->where('currency', $trip->currency)->orderBy('sequence')->get() as $leg) {
            $total = Money::add($total, (string) $leg->booked_cost);
            $byType[$leg->leg_type] = Money::add($byType[$leg->leg_type] ?? '0.00', (string) $leg->booked_cost);
        }

        return ['total' => $total, 'by_type' => $byType];
    }

    private function assertEditable(TravelTrip $trip): void
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
        $attributes = array_intersect_key($data, array_flip([
            'sequence', 'leg_type', 'from_location', 'to_location', 'departs_at', 'arrives_at', 'provider', 'booking_reference', 'booking_status', 'booked_cost', 'currency', 'notes',
        ]));
        $attributes['currency'] ??= $trip->currency;

        if ($attributes['currency'] !== $trip->currency) {
            throw ValidationException::withMessages(['currency' => __('travel_errors.leg.currency_mismatch', ['trip' => $trip->currency])]);
        }

        if (isset($attributes['booked_cost'])) {
            $attributes['booked_cost'] = Money::normalize($attributes['booked_cost']);
        }

        if (($attributes['leg_type'] ?? null) === 'hotel' && (empty($attributes['departs_at']) || empty($attributes['arrives_at']))) {
            throw ValidationException::withMessages(['departs_at' => __('travel_errors.leg.hotel_needs_dates')]);
        }

        return $attributes;
    }
}
