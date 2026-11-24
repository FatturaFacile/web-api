<?php

declare(strict_types=1);

namespace Modules\Inventory\Contracts;

use Modules\Inventory\DTOs\CarrierBooking;
use Modules\Inventory\DTOs\CarrierTrackingEvent;
use Modules\Inventory\Models\Shipment;
use Modules\Inventory\Models\ShippingCarrier;

/**
 * What a carrier integration must do: book a shipment and hand back its label, and report where it is. A driver for an
 * external API lives next to `LocalCarrierDriver` and is added to `ShippingManager`; failures throw `CarrierException`.
 */
interface ShippingCarrierDriver
{
    public function book(ShippingCarrier $carrier, Shipment $shipment): CarrierBooking;

    /**
     * @return list<CarrierTrackingEvent>
     */
    public function track(ShippingCarrier $carrier, Shipment $shipment): array;
}
