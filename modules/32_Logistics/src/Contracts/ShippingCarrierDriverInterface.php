<?php

declare(strict_types=1);

namespace Modules\Logistics\Contracts;

use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Logistics\Support\ManualCarrierDriver;
use Modules\Logistics\Support\ShipmentResult;

/**
 * Extension point for carrier integrations (Issue #158: CMR documents and external carrier APIs).
 * The default {@see ManualCarrierDriver} performs no remote call.
 */
interface ShippingCarrierDriverInterface
{
    public function code(): string;

    /** Whether this driver handles the carrier / courier set on the delivery note. */
    public function supports(SalesDeliveryNote $note): bool;

    public function trackingUrl(SalesDeliveryNote $note): ?string;

    /** Books the shipment with the carrier; called when the DDT goes in transit. */
    public function createShipment(SalesDeliveryNote $note): ShipmentResult;

    /** Cancels a booked shipment; called when an in-transit DDT is cancelled. */
    public function cancelShipment(SalesDeliveryNote $note): void;

    /** Hook for international (e.g. IT <-> CH) road transport: returns the CMR document reference, or null if unsupported. */
    public function generateCmr(SalesDeliveryNote $note): ?string;
}
