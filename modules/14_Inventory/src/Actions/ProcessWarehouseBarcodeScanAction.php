<?php

declare(strict_types=1);

namespace Modules\Inventory\Actions;

use Modules\Inventory\Contracts\BarcodeScannerServiceInterface;
use Modules\Inventory\DTOs\BarcodeScanDTO;

final readonly class ProcessWarehouseBarcodeScanAction
{
    public function __construct(
        private BarcodeScannerServiceInterface $service
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(BarcodeScanDTO $dto): array
    {
        return $this->service->parseBarcode($dto);
    }
}
