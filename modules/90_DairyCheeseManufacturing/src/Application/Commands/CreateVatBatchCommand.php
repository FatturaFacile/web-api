<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Application\Commands;

final readonly class CreateVatBatchCommand
{
    /**
     * @param  array<string, mixed>  $data  cheese_type, production_date, vat_number, tank_usages[{tank_id, liters}]
     */
    public function __construct(public array $data, public ?string $userId = null) {}
}
