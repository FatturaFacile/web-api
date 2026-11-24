<?php

declare(strict_types=1);

namespace Modules\Inventory\Contracts;

use Modules\Inventory\DTOs\BarcodeScanDTO;

interface BarcodeScannerServiceInterface
{
    /**
     * @return array<string, mixed>
     */
    public function parseBarcode(BarcodeScanDTO $dto): array;
}
