<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

final readonly class CarrierTrackingEvent
{
    public function __construct(
        public string $status,
        public string $occurredAt,
        public ?string $description = null,
        public ?string $location = null
    ) {}
}
