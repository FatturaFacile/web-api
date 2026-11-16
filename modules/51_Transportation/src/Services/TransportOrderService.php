<?php

declare(strict_types=1);

namespace Modules\Transportation\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Transportation\Models\TmsCarrier;
use Modules\Transportation\Models\TmsTransportOrder;
use Modules\Transportation\Models\TmsTrip;

/**
 * Transport orders. An order is a draft, then planned (ready for a trip and for tracking events), then on the road
 * (in transit, exception) and finally delivered; it can be cancelled until it moves. The number is taken under a lock.
 */
final class TransportOrderService extends TransportationCrudService
{
    /** @var list<string> */
    private const array With = ['carrier:id,code,name', 'trip:id,number,status'];

    public function __construct(private readonly TmsNumbering $numbering, private readonly RateCalculator $rates) {}

    protected function modelClass(): string
    {
        return TmsTransportOrder::class;
    }

    public function create(string $tenantId, array $data): Model
    {
        return DB::transaction(function () use ($tenantId, $data): Model {
            $data['number'] = $this->numbering->next(TmsTransportOrder::class, 'number', 'TO', $tenantId);

            return parent::create($tenantId, $data)->load(self::With);
        });
    }

    protected function prepare(string $tenantId, array $data, ?Model $existing): array
    {
        /** @var TmsTransportOrder|null $existing */
        if ($existing !== null && ! in_array($existing->status, [TmsTransportOrder::Draft, TmsTransportOrder::Planned], true)) {
            throw ValidationException::withMessages(['status' => [__('transportation_errors.order.not_editable', ['status' => $existing->status])]]);
        }

        if (isset($data['currency'])) {
            $data['currency'] = Str::upper((string) $data['currency']);
        }

        return $data;
    }

    protected function assertDeletable(Model $record): void
    {
        /** @var TmsTransportOrder $record */
        if (! in_array($record->status, [TmsTransportOrder::Draft, TmsTransportOrder::Cancelled], true)) {
            throw ValidationException::withMessages(['status' => [__('transportation_errors.order.not_deletable', ['status' => $record->status])]]);
        }
    }

    public function plan(TmsTransportOrder $order): TmsTransportOrder
    {
        return DB::transaction(function () use ($order): TmsTransportOrder {
            $locked = $this->lock($order);
            $this->assertStatus($locked, [TmsTransportOrder::Draft]);
            $locked->update(['status' => TmsTransportOrder::Planned]);

            return $this->present($locked);
        });
    }

    /**
     * Assigns the carrier and the expected cost: the one given, else the rate of the carrier for the order.
     */
    public function assignCarrier(TmsTransportOrder $order, string $carrierId, ?string $expectedCost): TmsTransportOrder
    {
        return DB::transaction(function () use ($order, $carrierId, $expectedCost): TmsTransportOrder {
            $locked = $this->lock($order);
            $this->assertStatus($locked, [TmsTransportOrder::Draft, TmsTransportOrder::Planned]);
            $carrier = TmsCarrier::query()->where('tenant_id', $locked->tenant_id)->findOrFail($carrierId);

            if (! $carrier->is_active) {
                throw ValidationException::withMessages(['carrier_id' => [__('transportation_errors.order.carrier_inactive', ['carrier' => $carrier->name])]]);
            }

            if ($locked->trip_id !== null) {
                $tripCarrier = TmsTrip::query()->whereKey($locked->trip_id)->value('carrier_id');

                if ($tripCarrier !== null && $tripCarrier !== $carrier->id) {
                    throw ValidationException::withMessages(['carrier_id' => [__('transportation_errors.order.trip_carrier')]]);
                }
            }

            $currency = (string) $locked->currency;

            if ($expectedCost === null) {
                $offer = $this->rates->rate($carrier, (string) $locked->weight_kg, $locked->distance_km !== null ? (string) $locked->distance_km : null, $locked->origin_zone, $locked->destination_zone, $locked->pickup_from ?? now(), $currency);

                if ($offer === null) {
                    throw ValidationException::withMessages(['carrier_id' => [__('transportation_errors.rate.no_rate', ['carrier' => $carrier->name])]]);
                }

                $expectedCost = $offer['total_amount'];
            }

            $locked->update(['carrier_id' => $carrier->id, 'expected_cost' => $expectedCost, 'currency' => $currency]);

            return $this->present($locked);
        });
    }

    public function cancel(TmsTransportOrder $order): TmsTransportOrder
    {
        return DB::transaction(function () use ($order): TmsTransportOrder {
            $locked = $this->lock($order);
            $this->assertStatus($locked, [TmsTransportOrder::Draft, TmsTransportOrder::Planned]);

            if ($locked->trip_id !== null) {
                throw ValidationException::withMessages(['status' => [__('transportation_errors.order.on_trip')]]);
            }

            $locked->update(['status' => TmsTransportOrder::Cancelled]);

            return $this->present($locked);
        });
    }

    public function present(TmsTransportOrder $order): TmsTransportOrder
    {
        return $order->refresh()->load(self::With);
    }

    private function lock(TmsTransportOrder $order): TmsTransportOrder
    {
        return TmsTransportOrder::query()->lockForUpdate()->findOrFail($order->id);
    }

    /**
     * @param  list<string>  $allowed
     */
    private function assertStatus(TmsTransportOrder $order, array $allowed): void
    {
        if (! in_array($order->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => [__('transportation_errors.order.invalid_transition', ['status' => $order->status])]]);
        }
    }
}
