<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Application\Commands;

final readonly class RegisterMilkDeliveryCommand
{
    /**
     * @param  array<string, mixed>  $data  breeder_id, tank_id, collection_date, session, liters, density?, temperature_c?, route_code?, tanker_plate?, notes?
     */
    public function __construct(public array $data, public ?string $userId = null) {}
}
