<?php

declare(strict_types=1);

namespace Modules\Logistics\Support;

use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Logistics\Contracts\ShippingCarrierDriverInterface;

/**
 * Default driver: the carrier is handled outside the system, tracking data is typed in by the operator.
 */
final class ManualCarrierDriver implements ShippingCarrierDriverInterface
{
    public function code(): string
    {
        return 'manual';
    }

    public function supports(SalesDeliveryNote $note): bool
    {
        return true;
    }

    public function trackingUrl(SalesDeliveryNote $note): ?string
    {
        return $note->carrier_tracking_url;
    }

    public function createShipment(SalesDeliveryNote $note): ShipmentResult
    {
        return new ShipmentResult($note->tracking_number, $note->carrier_tracking_url);
    }

    public function cancelShipment(SalesDeliveryNote $note): void {}

    public function generateCmr(SalesDeliveryNote $note): ?string
    {
        return null;
    }
}
