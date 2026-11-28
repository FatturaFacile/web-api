<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Application\Commands;

final readonly class RegisterMilkDeliveriesBulkCommand
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(public array $rows, public ?string $userId = null) {}
}
