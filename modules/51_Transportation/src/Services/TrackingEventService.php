<?php

declare(strict_types=1);

namespace Modules\Transportation\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Transportation\Models\TmsTrackingEvent;
use Modules\Transportation\Models\TmsTransportOrder;

/**
 * Tracking events drive the status of a transport order: a pickup, a hub scan or a delivery attempt puts a planned order on
 * the road, a delivery closes it, an exception flags it and a delay only leaves a trace. Orders that are draft, cancelled or
 * already delivered take no events.
 */
final class TrackingEventService extends TransportationCrudService
{
    /** Event types that mean the goods are moving. */
    private const array Moving = ['picked_up', 'in_transit', 'at_hub', 'out_for_delivery'];

    protected function modelClass(): string
    {
        return TmsTrackingEvent::class;
    }

    public function create(string $tenantId, array $data): Model
    {
        return DB::transaction(function () use ($tenantId, $data): Model {
            $order = TmsTransportOrder::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($data['transport_order_id']);

            return $this->record($order, (string) $data['event_type'], $data['location'] ?? null, Carbon::parse((string) $data['occurred_at']), $data['notes'] ?? null);
        });
    }

    /**
     * Stores the event and moves the order on. The order must be locked by the caller.
     */
    public function record(TmsTransportOrder $order, string $type, ?string $location, Carbon $at, ?string $notes = null): TmsTrackingEvent
    {
        if (! in_array($order->status, [TmsTransportOrder::Planned, TmsTransportOrder::InTransit, TmsTransportOrder::Exception], true)) {
            throw ValidationException::withMessages(['transport_order_id' => [__('transportation_errors.tracking.order_closed', ['number' => $order->number, 'status' => $order->status])]]);
        }

        $changes = match (true) {
            in_array($type, self::Moving, true) => ['status' => TmsTransportOrder::InTransit],
            $type === 'delivered' => ['status' => TmsTransportOrder::Delivered, 'delivered_at' => $at],
            $type === 'exception' => ['status' => TmsTransportOrder::Exception],
            default => [],
        };

        if ($changes !== []) {
            $order->update($changes);
        }

        return TmsTrackingEvent::create([
            'tenant_id' => $order->tenant_id,
            'transport_order_id' => $order->id,
            'event_type' => $type,
            'location' => $location,
            'occurred_at' => $at,
            'notes' => $notes,
        ])->refresh();
    }
}
