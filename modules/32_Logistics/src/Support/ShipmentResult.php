<?php

declare(strict_types=1);

namespace Modules\Logistics\Support;

final readonly class ShipmentResult
{
    public function __construct(
        public ?string $trackingNumber = null,
        public ?string $trackingUrl = null,
        public ?string $externalReference = null,
    ) {}
}
