<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Modules\PassengerTransport\Domain\Enums\BookingStatus;
use Modules\PassengerTransport\Domain\Enums\BorderCrossing;
use Modules\PassengerTransport\Domain\Enums\ExchangeDirection;
use Modules\PassengerTransport\Domain\Support\Dec;
use Modules\PassengerTransport\Domain\ValueObjects\RouteSplit;
use Modules\PassengerTransport\Domain\ValueObjects\VatInput;
use Modules\PassengerTransport\Exceptions\PassengerTransportException;
use Modules\PassengerTransport\Models\FleetVehicle;
use Modules\PassengerTransport\Models\PassengerBooking;

/**
 * Creates, prices and moves bookings. The route is split in the kilometres of each country when the coordinates are known, the
 * VAT split is previewed before saving, the concierge commission follows the agreed fare, and the status follows the trip.
 */
final class BookingService
{
    /** @var array<string, array{from: list<BookingStatus>, to: BookingStatus}> */
    private const array TRANSITIONS = [
        'confirm' => ['from' => [BookingStatus::Draft], 'to' => BookingStatus::Confirmed],
        'start_run' => ['from' => [BookingStatus::Assigned], 'to' => BookingStatus::EnRouteToPickup],
        'arrive' => ['from' => [BookingStatus::EnRouteToPickup], 'to' => BookingStatus::AtPickupWaiting],
        'board' => ['from' => [BookingStatus::AtPickupWaiting], 'to' => BookingStatus::PassengerOnBoard],
        'complete' => ['from' => [BookingStatus::PassengerOnBoard], 'to' => BookingStatus::Completed],
        'cancel' => ['from' => [BookingStatus::Draft, BookingStatus::Confirmed, BookingStatus::Assigned, BookingStatus::EnRouteToPickup, BookingStatus::AtPickupWaiting], 'to' => BookingStatus::Cancelled],
    ];

    public function __construct(
        private readonly CrossBorderRouteSplitCalculatorService $routes,
        private readonly RegulatoryStrategyResolver $strategies,
        private readonly DriverAssignmentService $assignment,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data, ?string $userId): PassengerBooking
    {
        $attributes = $this->prepare($data);

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn (): PassengerBooking => PassengerBooking::query()->create([
                    ...$attributes,
                    'tenant_id' => $tenantId,
                    'booking_code' => $this->nextCode(CarbonImmutable::parse((string) $attributes['pickup_datetime'])->year),
                    'status' => BookingStatus::Draft->value,
                    'created_by' => $userId,
                ]));
            } catch (QueryException $exception) {
                if ($attempt >= 3 || ! str_contains($exception->getMessage(), 'booking_code')) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PassengerBooking $booking, array $data, ?string $userId): PassengerBooking
    {
        if (! $booking->isEditable()) {
            throw new PassengerTransportException('booking_locked', 409);
        }

        $merged = [...Arr::except($booking->attributesToArray(), ['id', 'tenant_id', 'booking_code', 'status', 'created_at', 'updated_at', 'deleted_at']), ...$data];
        $routeChanged = array_intersect_key($data, array_flip(['pickup_lat', 'pickup_lng', 'dropoff_lat', 'dropoff_lng', 'pickup_country_code', 'dropoff_country_code', 'border_crossing_point', 'pickup_datetime']));
        $priceChanged = array_key_exists('agreed_price_net', $data) || array_key_exists('hotel_concierge_commission_rate', $data);
        $attributes = $routeChanged !== [] || $priceChanged ? $this->prepare($merged, $routeChanged !== []) : $data;

        $booking->update([...Arr::only($attributes, array_keys($data)), ...Arr::only($attributes, $this->derivedKeys($routeChanged !== [], $priceChanged)), 'updated_by' => $userId]);

        return $booking->refresh();
    }

    /**
     * @return array<string, mixed> the route split and the VAT breakdown of a trip, without saving it
     */
    public function quote(array $data): array
    {
        $attributes = $this->prepare($data);
        $strategy = $this->strategies->forCurrentTenant();
        $breakdown = $strategy->computeVat($this->vatInput($attributes));

        return [
            'route' => [
                'total_distance_km' => $attributes['total_distance_km'],
                'it_territory_km' => $attributes['it_territory_km'],
                'ch_territory_km' => $attributes['ch_territory_km'],
                'other_eu_territory_km' => $attributes['other_eu_territory_km'],
                'border_crossing_point' => $attributes['border_crossing_point'],
                'estimated_dropoff_datetime' => $attributes['estimated_dropoff_datetime'] ?? null,
            ],
            'vat' => $breakdown->toArray(),
            'hotel_concierge_commission_amount' => $attributes['hotel_concierge_commission_amount'],
        ];
    }

    public function transition(PassengerBooking $booking, string $action, ?string $userId): PassengerBooking
    {
        $rule = self::TRANSITIONS[$action] ?? throw new PassengerTransportException('booking_action_unknown', 422, ['action' => $action]);

        if (! in_array($booking->status, $rule['from'], true)) {
            throw new PassengerTransportException('booking_transition_refused', 409, ['status' => $booking->status->value, 'action' => $action]);
        }

        return DB::transaction(function () use ($booking, $rule, $action, $userId): PassengerBooking {
            $booking->update(['status' => $rule['to']->value, 'updated_by' => $userId]);

            if ($action === 'complete') {
                $this->assignment->recordCompletedTrip($booking);
            }

            return $booking->refresh();
        });
    }

    public function delete(PassengerBooking $booking, ?string $userId): void
    {
        if ($booking->status !== BookingStatus::Draft) {
            throw new PassengerTransportException('booking_delete_draft_only', 409);
        }

        $booking->update(['deleted_by' => $userId]);
        $booking->delete();
    }

    public function vatInput(array $attributes): VatInput
    {
        $vehicle = ! empty($attributes['assigned_vehicle_id']) ? FleetVehicle::query()->find((string) $attributes['assigned_vehicle_id']) : null;

        return new VatInput(
            (string) ($attributes['agreed_price_net'] ?? '0'),
            (string) ($attributes['currency'] ?? 'EUR'),
            (string) ($attributes['total_distance_km'] ?? '0'),
            (string) ($attributes['it_territory_km'] ?? '0'),
            (string) ($attributes['ch_territory_km'] ?? '0'),
            (string) ($attributes['other_eu_territory_km'] ?? '0'),
            $vehicle?->vehicle_category,
            (bool) ($attributes['municipal_urban_service'] ?? false),
            (bool) ($attributes['vat_exemption_art23'] ?? false),
        );
    }

    /**
     * Derives the kilometre split, the estimated drop-off and the commission of a booking from what was entered.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepare(array $data, bool $forceRoute = false): array
    {
        $data['pickup_country_code'] = strtoupper((string) ($data['pickup_country_code'] ?? 'IT'));
        $data['dropoff_country_code'] = strtoupper((string) ($data['dropoff_country_code'] ?? 'IT'));
        $crossing = BorderCrossing::tryFrom((string) ($data['border_crossing_point'] ?? 'none')) ?? BorderCrossing::None;
        $hasCoordinates = isset($data['pickup_lat'], $data['pickup_lng'], $data['dropoff_lat'], $data['dropoff_lng']);
        $manualKm = ! $forceRoute && Dec::cmp($data['total_distance_km'] ?? 0, '0') > 0;

        if ($hasCoordinates && ! $manualKm) {
            $split = $this->routes->calculate((float) $data['pickup_lat'], (float) $data['pickup_lng'], $data['pickup_country_code'], (float) $data['dropoff_lat'], (float) $data['dropoff_lng'], $data['dropoff_country_code'], $crossing);
            $data = [...$data, ...$this->kmAttributes($split)];

            if (empty($data['estimated_dropoff_datetime']) || $forceRoute) {
                $data['estimated_dropoff_datetime'] = CarbonImmutable::parse((string) $data['pickup_datetime'])->addMinutes($split->durationMinutes)->toDateTimeString();
            }
        } else {
            $data = $this->manualSplit($data, $crossing);
        }

        $rate = $data['hotel_concierge_commission_rate'] ?? null;
        $data['hotel_concierge_commission_amount'] = $rate === null || $rate === ''
            ? '0.00'
            : Dec::round(Dec::div(Dec::mul($data['agreed_price_net'] ?? 0, $rate), '100'));
        $data['exchange_direction'] ??= ExchangeDirection::None->value;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function manualSplit(array $data, BorderCrossing $crossing): array
    {
        $it = Dec::round($data['it_territory_km'] ?? 0);
        $ch = Dec::round($data['ch_territory_km'] ?? 0);
        $other = Dec::round($data['other_eu_territory_km'] ?? 0);
        $sum = Dec::add(Dec::add($it, $ch), $other);
        $total = Dec::cmp($data['total_distance_km'] ?? 0, '0') > 0 ? Dec::round($data['total_distance_km']) : Dec::round($sum);

        if (Dec::cmp($sum, '0') === 0 && Dec::cmp($total, '0') > 0) {
            $country = $data['pickup_country_code'] === $data['dropoff_country_code'] ? $data['pickup_country_code'] : 'IT';
            [$it, $ch, $other] = match ($country) {
                'CH' => ['0.00', $total, '0.00'],
                'IT' => [$total, '0.00', '0.00'],
                default => ['0.00', '0.00', $total],
            };
        }

        return [...$data, 'total_distance_km' => $total, 'it_territory_km' => $it, 'ch_territory_km' => $ch, 'other_eu_territory_km' => $other, 'border_crossing_point' => $crossing->value];
    }

    /**
     * @return array<string, string>
     */
    private function kmAttributes(RouteSplit $split): array
    {
        return [
            'total_distance_km' => $split->totalKm,
            'it_territory_km' => $split->itKm,
            'ch_territory_km' => $split->chKm,
            'other_eu_territory_km' => $split->otherEuKm,
            'border_crossing_point' => $split->crossing->value,
        ];
    }

    /**
     * @return list<string>
     */
    private function derivedKeys(bool $route, bool $price): array
    {
        return [
            ...($route ? ['total_distance_km', 'it_territory_km', 'ch_territory_km', 'other_eu_territory_km', 'border_crossing_point', 'estimated_dropoff_datetime'] : []),
            ...($price ? ['hotel_concierge_commission_amount'] : []),
        ];
    }

    private function nextCode(int $year): string
    {
        $prefix = sprintf('BK-%d-', $year);
        $last = PassengerBooking::withTrashed()->where('booking_code', 'like', $prefix.'%')->orderByDesc('booking_code')->value('booking_code');
        $next = $last === null ? 1 : ((int) substr((string) $last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
