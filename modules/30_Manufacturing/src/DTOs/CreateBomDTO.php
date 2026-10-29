<?php

declare(strict_types=1);

namespace Modules\Manufacturing\DTOs;

final readonly class CreateBomDTO
{
    /**
     * @param array<int, array{
     *     component_item_id: string,
     *     sequence?: int,
     *     quantity_per: float,
     *     scrap_percentage?: float,
     *     yield_percentage?: float,
     *     is_phantom?: bool,
     *     notes?: string|null
     * }> $components
     */
    public function __construct(
        public string $tenantId,
        public string $mfgItemId,
        public string $name,
        public string $revision = 'A',
        public string $bomType = 'standard',
        public float $baseQuantity = 1.0,
        public string $uom = 'PCS',
        public ?string $effectiveFrom = null,
        public ?string $effectiveTo = null,
        public array $components = []
    ) {}
}
