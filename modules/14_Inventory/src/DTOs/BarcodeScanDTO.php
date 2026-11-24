<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

final readonly class BarcodeScanDTO
{
    public function __construct(
        public string $rawBarcode,
        public ?string $warehouseId = null,
        public ?string $operationContext = null
    ) {}
}
