<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Application\Commands;

use Modules\DairyCheeseManufacturing\Models\MilkDelivery;
use Modules\DairyCheeseManufacturing\Services\DeliveryService;

final class RegisterMilkDeliveryHandler
{
    public function __construct(private readonly DeliveryService $deliveries) {}

    public function handle(RegisterMilkDeliveryCommand $command): MilkDelivery
    {
        return $this->deliveries->register($command->data, $command->userId)->load(['breeder', 'tank']);
    }
}
