<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Contracts;

use Modules\Manufacturing\DTOs\CreateBomDTO;
use Modules\Manufacturing\DTOs\CreateEcoDTO;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgEngineeringChangeOrder;

interface BillOfMaterialsServiceInterface
{
    public function createBom(CreateBomDTO $dto): MfgBom;

    /**
     * Explodes a BOM recursively returning all leaf and intermediate components.
     * Phantom BOM components are exploded directly into their child components.
     *
     * @return array<int, array{
     *     component_item_id: string,
     *     component_item_code: string,
     *     component_name: string,
     *     level: int,
     *     quantity_per: float,
     *     scrap_percentage: float,
     *     effective_quantity: float,
     *     uom: string,
     *     is_phantom: bool,
     *     is_subassembly: bool
     * }>
     */
    public function explodeBom(string $bomId, float $parentQuantity = 1.0, int $level = 1): array;

    public function reviseBom(string $bomId, string $newRevision, string $reason): MfgBom;

    public function createEco(CreateEcoDTO $dto): MfgEngineeringChangeOrder;

    public function approveEco(string $ecoId, string $approvedBy): MfgEngineeringChangeOrder;

    public function implementEco(string $ecoId): MfgEngineeringChangeOrder;
}
