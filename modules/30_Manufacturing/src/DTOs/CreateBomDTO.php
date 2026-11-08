<?php

declare(strict_types=1);

namespace Modules\Manufacturing\DTOs;

/**
 * Data of a new BOM revision (created as a draft). Quantities and percentages are decimal strings.
 */
final readonly class CreateBomDTO
{
    /**
     * @param  list<array{component_item_id: string, quantity_per: string|int|float, sequence?: int|null, scrap_percentage?: string|int|float|null, yield_percentage?: string|int|float|null, is_phantom?: bool|null, issue_method?: string|null, operation_sequence?: int|null, notes?: string|null}>  $components
     */
    public function __construct(
        public string $tenantId,
        public string $mfgItemId,
        public string $name,
        public array $components,
        public string $revision = 'A',
        public string $bomType = 'standard',
        public string $baseQuantity = '1',
        public string $uom = 'PCS',
        public ?string $effectiveFrom = null,
        public ?string $effectiveTo = null,
        public ?string $notes = null,
        public ?string $bomNumber = null,
    ) {}
}
