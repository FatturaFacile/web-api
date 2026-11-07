<?php

declare(strict_types=1);

namespace Modules\Inventory\Services\Shipping;

use App\Enums\BarcodeType;
use App\Services\Inventory\BarcodeService;
use Illuminate\Support\Str;
use Modules\Inventory\Contracts\ShippingCarrierDriver;
use Modules\Inventory\DTOs\CarrierBooking;
use Modules\Inventory\Models\Shipment;
use Modules\Inventory\Models\ShippingCarrier;

/**
 * A carrier with no API, such as the company's own vans or a local haulier: the tracking number and the label are made
 * here, and tracking events are entered by hand, so `track` has nothing to fetch.
 */
final class LocalCarrierDriver implements ShippingCarrierDriver
{
    public function __construct(
        private readonly BarcodeService $barcodes
    ) {}

    public function book(ShippingCarrier $carrier, Shipment $shipment): CarrierBooking
    {
        $trackingNumber = strtoupper($carrier->code).'-'.strtoupper(Str::random(12));

        return new CarrierBooking(
            trackingNumber: $trackingNumber,
            labelFormat: 'html',
            labelContent: $this->label($carrier, $shipment, $trackingNumber),
        );
    }

    public function track(ShippingCarrier $carrier, Shipment $shipment): array
    {
        return [];
    }

    private function label(ShippingCarrier $carrier, Shipment $shipment, string $trackingNumber): string
    {
        $recipient = $shipment->recipient;
        $lines = array_filter([$recipient['name'] ?? null, $recipient['address'] ?? null, trim(($recipient['postal_code'] ?? '').' '.($recipient['city'] ?? '')), $recipient['country'] ?? null]);
        $address = implode('<br>', array_map(static fn (string $line): string => e($line), $lines));
        $barcode = $this->barcodes->svg(BarcodeType::Code128, $trackingNumber);

        return '<div class="shipping-label"><h1>'.e($carrier->name).'</h1><p>'.$address.'</p><p>'
            .e((string) $shipment->packages_count).' pkg, '.e((string) $shipment->gross_weight_kg).' kg</p>'.$barcode.'<p>'.e($trackingNumber).'</p></div>';
    }
}
