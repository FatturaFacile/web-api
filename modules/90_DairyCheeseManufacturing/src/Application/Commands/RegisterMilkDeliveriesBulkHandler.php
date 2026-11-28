<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Application\Commands;

use Modules\DairyCheeseManufacturing\Models\MilkDelivery;
use Modules\DairyCheeseManufacturing\Services\DeliveryService;

final class RegisterMilkDeliveriesBulkHandler
{
    public function __construct(private readonly DeliveryService $deliveries) {}

    /**
     * @return array{created: list<MilkDelivery>, rejected: list<array{index: int, errors: list<string>}>}
     */
    public function handle(RegisterMilkDeliveriesBulkCommand $command): array
    {
        $result = $this->deliveries->registerBulk($command->rows, $command->userId);

        foreach ($result['created'] as $delivery) {
            $delivery->load(['breeder', 'tank']);
        }

        return $result;
    }
}
