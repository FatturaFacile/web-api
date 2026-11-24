<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Enums\ValuationMethod;

final readonly class OutboundCostingResult
{
    /**
     * @param  list<array{layer_id: string, quantity: string, unit_cost: string, cogs_amount: string}>  $consumptions
     */
    public function __construct(
        public ValuationMethod $method,
        public string $unitCost,
        public string $cogsAmount,
        public array $consumptions,
    ) {}
}
