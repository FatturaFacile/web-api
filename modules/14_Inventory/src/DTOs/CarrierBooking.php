<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

final readonly class CarrierBooking
{
    public function __construct(
        public string $trackingNumber,
        public string $labelFormat,
        public string $labelContent,
        public ?string $carrierReference = null
    ) {}
}
