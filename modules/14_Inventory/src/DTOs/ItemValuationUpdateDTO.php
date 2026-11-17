<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Enums\ValuationMethod;

final readonly class ItemValuationUpdateDTO
{
    /**
     * @param  bool  $methodProvided  whether `valuation_method` was present in the request (null = inherit from category)
     */
    public function __construct(
        public string $itemId,
        public bool $methodProvided,
        public ?ValuationMethod $valuationMethod,
        public ?string $standardCost = null,
        public bool $weightProvided = false,
        public ?string $weightKg = null,
        public bool $volumeProvided = false,
        public ?string $volumeM3 = null,
        public bool $revalueOnChange = true,
        public ?string $userId = null,
    ) {}
}
