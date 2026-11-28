<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Application\Commands;

use Modules\DairyCheeseManufacturing\Models\CheeseAgingWheel;
use Modules\DairyCheeseManufacturing\Services\WheelService;

final class SellWheelsHandler
{
    public function __construct(private readonly WheelService $wheels) {}

    /**
     * @return array{sold: list<CheeseAgingWheel>, total_kg: string, total_variance_kg: string}
     */
    public function handle(SellWheelsCommand $command): array
    {
        return $this->wheels->sell($command->wheelIds, $command->customerId, $command->customerName, $command->weights, $command->invoiceRef);
    }
}
