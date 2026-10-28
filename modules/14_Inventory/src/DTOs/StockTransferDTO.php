<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

final readonly class StockTransferDTO
{
    public function __construct(
        public string $stockItemId,
        public ?string $sourceBinId,
        public ?string $destinationBinId,
        public float $quantity,
        public string $movementType,
        public ?string $lotId = null,
        public ?string $serialNumberId = null,
        public ?string $originDocumentType = null,
        public ?string $originDocumentId = null,
        public ?string $userId = null,
        public ?float $customUnitCost = null,
        public ?string $notes = null
    ) {}
}
