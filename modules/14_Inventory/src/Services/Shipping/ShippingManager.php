<?php

declare(strict_types=1);

namespace Modules\Inventory\Services\Shipping;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Contracts\ShippingCarrierDriver;
use Modules\Inventory\DTOs\CarrierTrackingEvent;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\Shipment;
use Modules\Inventory\Models\ShipmentTrackingEvent;
use Modules\Inventory\Models\ShippingCarrier;
use Modules\Inventory\Models\StockPackingList;

/**
 * Books shipments through the driver of each carrier and keeps their tracking history. A carrier never moves a
 * delivered shipment back: statuses arriving late are stored but leave the shipment delivered.
 */
final class ShippingManager
{
    /** @var array<string, class-string<ShippingCarrierDriver>> */
    private const array DRIVERS = ['local' => LocalCarrierDriver::class, 'dpd_ch' => DpdSwitzerlandDriver::class];

    public const array STATUSES = ['booked', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered', 'failed', 'returned'];

    /**
     * @return list<string>
     */
    public static function driverNames(): array
    {
        return array_keys(self::DRIVERS);
    }

    public function driverFor(ShippingCarrier $carrier): ShippingCarrierDriver
    {
        $driver = self::DRIVERS[$carrier->driver] ?? null;

        if ($driver === null) {
            throw new StockRuleViolation("Carrier driver [{$carrier->driver}] is not available.");
        }

        return app($driver);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function book(ShippingCarrier $carrier, array $data, string $userId): Shipment
    {
        if (! $carrier->is_active) {
            throw new StockRuleViolation("Carrier [{$carrier->code}] is not active.");
        }

        $packingList = isset($data['stock_packing_list_id']) ? StockPackingList::query()->findOrFail($data['stock_packing_list_id']) : null;

        return DB::transaction(function () use ($carrier, $data, $userId, $packingList): Shipment {
            $shipment = new Shipment([
                'tenant_id' => $carrier->tenant_id,
                'shipping_carrier_id' => $carrier->id,
                'stock_packing_list_id' => $packingList?->id,
                'delivery_note_id' => $data['delivery_note_id'] ?? $packingList?->delivery_note_id,
                'shipment_number' => 'SHP-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
                'status' => 'booked',
                'recipient' => $data['recipient'],
                'packages_count' => $data['packages_count'] ?? $packingList?->total_packages ?? 1,
                'gross_weight_kg' => $data['gross_weight_kg'] ?? $packingList?->total_weight_kg,
                'created_by' => $userId,
            ]);

            $booking = $this->driverFor($carrier)->book($carrier, $shipment);

            $shipment->fill([
                'tracking_number' => $booking->trackingNumber,
                'carrier_reference' => $booking->carrierReference,
                'label_format' => $booking->labelFormat,
                'label_content' => $booking->labelContent,
            ]);
            $shipment->save();

            if ($packingList !== null) {
                $packingList->update(['shipping_carrier' => $carrier->name, 'tracking_number' => $booking->trackingNumber]);
            }

            $this->record($shipment, new CarrierTrackingEvent('booked', now()->toIso8601String()), 'system');

            return $shipment;
        });
    }

    /**
     * Asks the carrier for news and stores what is new.
     */
    public function refresh(Shipment $shipment): Shipment
    {
        foreach ($this->driverFor($shipment->carrier)->track($shipment->carrier, $shipment) as $event) {
            $this->record($shipment, $event, 'carrier');
        }

        return $shipment;
    }

    public function record(Shipment $shipment, CarrierTrackingEvent $event, string $source): ShipmentTrackingEvent
    {
        return DB::transaction(function () use ($shipment, $event, $source): ShipmentTrackingEvent {
            $locked = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOr(static fn (): Shipment => $shipment);

            $stored = ShipmentTrackingEvent::query()->firstOrCreate(
                ['shipment_id' => $shipment->id, 'status' => $event->status, 'occurred_at' => $event->occurredAt],
                ['tenant_id' => $shipment->tenant_id, 'description' => $event->description, 'location' => $event->location, 'source' => $source]
            );

            if ($locked->status !== 'delivered') {
                $locked->update(['status' => $event->status, 'delivered_at' => $event->status === 'delivered' ? $event->occurredAt : null]);
            }

            $shipment->setRawAttributes($locked->getAttributes(), true);

            return $stored;
        });
    }
}
