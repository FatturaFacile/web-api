<?php

declare(strict_types=1);

namespace Modules\Transportation\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Transportation\Models\TmsTransportOrder;
use Modules\Transportation\Models\TmsTrip;
use Modules\Transportation\Models\TmsTripStop;

/**
 * Trip planning. A trip consolidates planned transport orders (a pickup and a delivery stop each) as long as the total
 * weight and volume fit its capacity; the stops are ordered by the planner and completed one after the other while the trip
 * is in progress. A completed pickup puts the order on the road, a completed delivery delivers it.
 */
final class TripService extends TransportationCrudService
{
    /** @var list<string> */
    private const array With = ['carrier:id,code,name', 'stops.transportOrder:id,number'];

    public function __construct(private readonly TmsNumbering $numbering, private readonly TrackingEventService $tracking) {}

    protected function modelClass(): string
    {
        return TmsTrip::class;
    }

    public function create(string $tenantId, array $data): Model
    {
        return DB::transaction(function () use ($tenantId, $data): Model {
            $data['number'] = $this->numbering->next(TmsTrip::class, 'number', 'TR', $tenantId);
            $trip = parent::create($tenantId, $data);

            return $this->present($trip);
        });
    }

    protected function prepare(string $tenantId, array $data, ?Model $existing): array
    {
        /** @var TmsTrip|null $existing */
        if ($existing !== null) {
            $this->assertStatus($existing, [TmsTrip::Planned, TmsTrip::Dispatched], 'transportation_errors.trip.not_editable');

            $weight = array_key_exists('capacity_weight_kg', $data) ? $data['capacity_weight_kg'] : $existing->capacity_weight_kg;
            $volume = array_key_exists('capacity_volume_cbm', $data) ? $data['capacity_volume_cbm'] : $existing->capacity_volume_cbm;

            if ($weight !== null && bccomp((string) $weight, (string) $existing->total_weight_kg, 3) < 0) {
                throw ValidationException::withMessages(['capacity_weight_kg' => [__('transportation_errors.trip.capacity_below_load', ['load' => (string) $existing->total_weight_kg])]]);
            }

            if ($volume !== null && bccomp((string) $volume, (string) $existing->total_volume_cbm, 3) < 0) {
                throw ValidationException::withMessages(['capacity_volume_cbm' => [__('transportation_errors.trip.capacity_below_load', ['load' => (string) $existing->total_volume_cbm])]]);
            }
        }

        if (isset($data['currency'])) {
            $data['currency'] = Str::upper((string) $data['currency']);
        }

        return $data;
    }

    public function update(Model $record, array $data): Model
    {
        return $this->present(parent::update($record, $data));
    }

    protected function assertDeletable(Model $record): void
    {
        /** @var TmsTrip $record */
        if ($record->status !== TmsTrip::Planned || TmsTransportOrder::query()->where('trip_id', $record->id)->exists()) {
            throw ValidationException::withMessages(['status' => [__('transportation_errors.trip.not_deletable')]]);
        }
    }

    /**
     * @param  list<string>  $orderIds
     */
    public function addOrders(TmsTrip $trip, array $orderIds): TmsTrip
    {
        return DB::transaction(function () use ($trip, $orderIds): TmsTrip {
            $locked = $this->lock($trip);
            $this->assertStatus($locked, [TmsTrip::Planned, TmsTrip::Dispatched]);
            $orderIds = array_values(array_unique($orderIds));
            $orders = TmsTransportOrder::query()->where('tenant_id', $locked->tenant_id)->whereIn('id', $orderIds)->lockForUpdate()->get();

            if ($orders->count() !== count($orderIds)) {
                throw ValidationException::withMessages(['order_ids' => [__('transportation_errors.trip.order_not_found')]]);
            }

            $weight = (string) $locked->total_weight_kg;
            $volume = (string) $locked->total_volume_cbm;

            foreach ($orders as $order) {
                if ($order->status !== TmsTransportOrder::Planned) {
                    throw ValidationException::withMessages(['order_ids' => [__('transportation_errors.trip.order_not_planned', ['number' => $order->number, 'status' => $order->status])]]);
                }

                if ($order->trip_id !== null) {
                    throw ValidationException::withMessages(['order_ids' => [__('transportation_errors.trip.order_on_trip', ['number' => $order->number])]]);
                }

                if ($locked->carrier_id !== null && $order->carrier_id !== null && $order->carrier_id !== $locked->carrier_id) {
                    throw ValidationException::withMessages(['order_ids' => [__('transportation_errors.trip.order_other_carrier', ['number' => $order->number])]]);
                }

                $weight = bcadd($weight, (string) $order->weight_kg, 3);
                $volume = bcadd($volume, (string) $order->volume_cbm, 3);
            }

            if ($locked->capacity_weight_kg !== null && bccomp($weight, (string) $locked->capacity_weight_kg, 3) > 0) {
                throw ValidationException::withMessages(['order_ids' => [__('transportation_errors.trip.over_weight', ['total' => $weight, 'capacity' => (string) $locked->capacity_weight_kg])]]);
            }

            if ($locked->capacity_volume_cbm !== null && bccomp($volume, (string) $locked->capacity_volume_cbm, 3) > 0) {
                throw ValidationException::withMessages(['order_ids' => [__('transportation_errors.trip.over_volume', ['total' => $volume, 'capacity' => (string) $locked->capacity_volume_cbm])]]);
            }

            // Pickups are inserted after the last pickup already planned, deliveries are appended.
            $sequence = $locked->stops()->orderBy('sequence')->get()->map(static fn (TmsTripStop $stop): array => ['stop' => $stop, 'data' => null])->all();

            foreach ($orderIds as $orderId) {
                $order = $orders->firstWhere('id', $orderId);
                $lastPickup = -1;

                foreach ($sequence as $index => $item) {
                    if (($item['stop']->stop_type ?? $item['data']['stop_type']) === 'pickup') {
                        $lastPickup = $index;
                    }
                }

                array_splice($sequence, $lastPickup + 1, 0, [['stop' => null, 'data' => ['stop_type' => 'pickup', 'transport_order_id' => $order->id, 'location' => $order->origin_location, 'city' => $order->origin_city, 'planned_arrival_at' => $order->pickup_from]]]);
                $sequence[] = ['stop' => null, 'data' => ['stop_type' => 'delivery', 'transport_order_id' => $order->id, 'location' => $order->destination_location, 'city' => $order->destination_city, 'planned_arrival_at' => $order->delivery_from]];
                $order->update(['trip_id' => $locked->id]);
            }

            $this->writeSequence($locked, $sequence);
            $this->recalculate($locked);

            return $this->present($locked);
        });
    }

    public function removeOrder(TmsTrip $trip, string $orderId): TmsTrip
    {
        return DB::transaction(function () use ($trip, $orderId): TmsTrip {
            $locked = $this->lock($trip);
            $this->assertStatus($locked, [TmsTrip::Planned, TmsTrip::Dispatched]);
            $order = TmsTransportOrder::query()->where('tenant_id', $locked->tenant_id)->where('trip_id', $locked->id)->find($orderId);

            if ($order === null) {
                throw ValidationException::withMessages(['order_id' => [__('transportation_errors.trip.order_not_on_trip')]]);
            }

            TmsTripStop::query()->where('trip_id', $locked->id)->where('transport_order_id', $order->id)->delete();
            $order->update(['trip_id' => null]);
            $this->writeSequence($locked, $locked->stops()->orderBy('sequence')->get()->map(static fn (TmsTripStop $stop): array => ['stop' => $stop, 'data' => null])->all());
            $this->recalculate($locked);

            return $this->present($locked);
        });
    }

    /**
     * @param  list<string>  $stopIds
     */
    public function reorderStops(TmsTrip $trip, array $stopIds): TmsTrip
    {
        return DB::transaction(function () use ($trip, $stopIds): TmsTrip {
            $locked = $this->lock($trip);
            $this->assertStatus($locked, [TmsTrip::Planned, TmsTrip::Dispatched]);
            $stops = $locked->stops()->get()->keyBy('id');

            if (count($stopIds) !== $stops->count() || array_diff($stopIds, $stops->keys()->all()) !== []) {
                throw ValidationException::withMessages(['stop_ids' => [__('transportation_errors.trip.stops_mismatch')]]);
            }

            $positions = array_flip($stopIds);

            foreach ($stops->where('stop_type', 'pickup') as $pickup) {
                $delivery = $stops->first(static fn (TmsTripStop $stop): bool => $stop->stop_type === 'delivery' && $stop->transport_order_id === $pickup->transport_order_id);

                if ($delivery !== null && $positions[$pickup->id] > $positions[$delivery->id]) {
                    throw ValidationException::withMessages(['stop_ids' => [__('transportation_errors.trip.delivery_before_pickup')]]);
                }
            }

            $this->writeSequence($locked, array_map(static fn (string $id): array => ['stop' => $stops[$id], 'data' => null], $stopIds));

            return $this->present($locked);
        });
    }

    public function completeStop(TmsTrip $trip, string $stopId, ?string $arrivedAt, ?string $departedAt): TmsTrip
    {
        return DB::transaction(function () use ($trip, $stopId, $arrivedAt, $departedAt): TmsTrip {
            $locked = $this->lock($trip);
            $this->assertStatus($locked, [TmsTrip::InProgress]);
            $stop = $locked->stops()->find($stopId);

            if ($stop === null) {
                throw ValidationException::withMessages(['stop_id' => [__('transportation_errors.trip.stop_not_found')]]);
            }

            if ($stop->status !== TmsTripStop::Pending) {
                throw ValidationException::withMessages(['stop_id' => [__('transportation_errors.trip.stop_done')]]);
            }

            if ($locked->stops()->where('status', TmsTripStop::Pending)->where('sequence', '<', $stop->sequence)->exists()) {
                throw ValidationException::withMessages(['stop_id' => [__('transportation_errors.trip.stop_out_of_sequence')]]);
            }

            $arrived = $arrivedAt !== null ? Carbon::parse($arrivedAt) : now();
            $departed = $departedAt !== null ? Carbon::parse($departedAt) : $arrived;

            if ($departed->lessThan($arrived)) {
                throw ValidationException::withMessages(['departed_at' => [__('transportation_errors.trip.departure_before_arrival')]]);
            }

            $stop->update(['status' => TmsTripStop::Completed, 'actual_arrival_at' => $arrived, 'actual_departure_at' => $departed]);

            if ($stop->transport_order_id !== null) {
                $order = TmsTransportOrder::query()->lockForUpdate()->findOrFail($stop->transport_order_id);
                $this->tracking->record($order, $stop->stop_type === 'pickup' ? 'picked_up' : 'delivered', $stop->location, $stop->stop_type === 'pickup' ? $departed : $arrived);
            }

            return $this->present($locked);
        });
    }

    public function dispatch(TmsTrip $trip): TmsTrip
    {
        return DB::transaction(function () use ($trip): TmsTrip {
            $locked = $this->lock($trip);
            $this->assertStatus($locked, [TmsTrip::Planned]);

            if (! $locked->stops()->exists()) {
                throw ValidationException::withMessages(['status' => [__('transportation_errors.trip.no_stops')]]);
            }

            $locked->update(['status' => TmsTrip::Dispatched]);

            return $this->present($locked);
        });
    }

    public function start(TmsTrip $trip): TmsTrip
    {
        return DB::transaction(function () use ($trip): TmsTrip {
            $locked = $this->lock($trip);
            $this->assertStatus($locked, [TmsTrip::Dispatched]);
            $locked->update(['status' => TmsTrip::InProgress, 'actual_start_at' => now()]);

            return $this->present($locked);
        });
    }

    public function complete(TmsTrip $trip, ?string $actualDistanceKm, ?string $actualCost): TmsTrip
    {
        return DB::transaction(function () use ($trip, $actualDistanceKm, $actualCost): TmsTrip {
            $locked = $this->lock($trip);
            $this->assertStatus($locked, [TmsTrip::InProgress]);
            $pending = $locked->stops()->where('status', TmsTripStop::Pending)->count();

            if ($pending > 0) {
                throw ValidationException::withMessages(['status' => [__('transportation_errors.trip.stops_pending', ['count' => $pending])]]);
            }

            $locked->update([
                'status' => TmsTrip::Completed,
                'actual_end_at' => now(),
                'actual_distance_km' => $actualDistanceKm ?? $locked->planned_distance_km,
                'actual_cost' => $actualCost,
            ]);

            return $this->present($locked);
        });
    }

    public function cancel(TmsTrip $trip): TmsTrip
    {
        return DB::transaction(function () use ($trip): TmsTrip {
            $locked = $this->lock($trip);
            $this->assertStatus($locked, [TmsTrip::Planned, TmsTrip::Dispatched]);
            TmsTransportOrder::query()->where('trip_id', $locked->id)->update(['trip_id' => null]);
            TmsTripStop::query()->where('trip_id', $locked->id)->delete();
            $locked->update(['status' => TmsTrip::Cancelled, 'total_weight_kg' => '0', 'total_volume_cbm' => '0', 'utilization_percent' => null]);

            return $this->present($locked);
        });
    }

    /**
     * Weight, volume and utilization of the trip from the orders it carries.
     */
    private function recalculate(TmsTrip $trip): void
    {
        $orders = TmsTransportOrder::query()->where('trip_id', $trip->id)->get(['weight_kg', 'volume_cbm']);
        $weight = '0';
        $volume = '0';

        foreach ($orders as $order) {
            $weight = bcadd($weight, (string) $order->weight_kg, 3);
            $volume = bcadd($volume, (string) $order->volume_cbm, 3);
        }

        $ratios = [];

        if ($trip->capacity_weight_kg !== null && bccomp((string) $trip->capacity_weight_kg, '0', 3) > 0) {
            $ratios[] = bcround(bcdiv(bcmul($weight, '100', 8), (string) $trip->capacity_weight_kg, 8), 2, \RoundingMode::HalfAwayFromZero);
        }

        if ($trip->capacity_volume_cbm !== null && bccomp((string) $trip->capacity_volume_cbm, '0', 3) > 0) {
            $ratios[] = bcround(bcdiv(bcmul($volume, '100', 8), (string) $trip->capacity_volume_cbm, 8), 2, \RoundingMode::HalfAwayFromZero);
        }

        $utilization = null;

        foreach ($ratios as $ratio) {
            $utilization = $utilization === null || bccomp($ratio, $utilization, 2) > 0 ? $ratio : $utilization;
        }

        $trip->update(['total_weight_kg' => $weight, 'total_volume_cbm' => $volume, 'utilization_percent' => $utilization]);
    }

    /**
     * Stores the order of the stops: existing stops are renumbered, new ones created.
     *
     * @param  list<array{stop: TmsTripStop|null, data: array<string, mixed>|null}>  $sequence
     */
    private function writeSequence(TmsTrip $trip, array $sequence): void
    {
        foreach ($sequence as $index => $item) {
            if ($item['stop'] !== null) {
                $item['stop']->update(['sequence' => $index + 1]);

                continue;
            }

            TmsTripStop::create([...$item['data'], 'tenant_id' => $trip->tenant_id, 'trip_id' => $trip->id, 'sequence' => $index + 1]);
        }
    }

    private function present(Model $trip): TmsTrip
    {
        /** @var TmsTrip $trip */
        return $trip->refresh()->load(self::With);
    }

    private function lock(TmsTrip $trip): TmsTrip
    {
        return TmsTrip::query()->lockForUpdate()->findOrFail($trip->id);
    }

    /**
     * @param  list<string>  $allowed
     */
    private function assertStatus(TmsTrip $trip, array $allowed, string $message = 'transportation_errors.trip.invalid_transition'): void
    {
        if (! in_array($trip->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => [__($message, ['status' => $trip->status])]]);
        }
    }
}
