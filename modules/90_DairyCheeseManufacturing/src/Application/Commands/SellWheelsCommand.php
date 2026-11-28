<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Application\Commands;

final readonly class SellWheelsCommand
{
    /**
     * @param  list<string>  $wheelIds
     * @param  array<string, float|int|string>  $weights
     */
    public function __construct(public array $wheelIds, public ?string $customerId, public string $customerName, public array $weights, public ?string $invoiceRef) {}
}
