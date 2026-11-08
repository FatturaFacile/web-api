<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Contracts;

use Illuminate\Support\Collection;
use Modules\Manufacturing\DTOs\CreateBomDTO;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgBomComponent;
use Modules\Manufacturing\Models\MfgItem;

/**
 * Multi-level bills of materials with revisions and effective dates.
 */
interface BillOfMaterialsServiceInterface
{
    /**
     * Creates a draft BOM revision with its components, refusing components that would make the BOM recursive.
     */
    public function createBom(CreateBomDTO $dto, ?string $actorUserId = null): MfgBom;

    /**
     * Replaces header data and components of a draft revision.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateDraft(MfgBom $bom, array $data, ?string $actorUserId = null): MfgBom;

    /**
     * Releases a draft revision: it becomes effective from its effective date and the revision effective before it
     * ends the day before (superseded).
     */
    public function activate(MfgBom $bom, ?string $actorUserId = null): MfgBom;

    /**
     * Copies a revision into a new draft revision of the same BOM number, optionally applying component changes.
     *
     * @param  list<array<string, mixed>>  $componentChanges
     */
    public function reviseBom(MfgBom $bom, ?string $newRevision, string $reason, array $componentChanges = [], ?string $effectiveFrom = null, ?string $actorUserId = null): MfgBom;

    /**
     * The revision of the item's BOM effective on the date, if any.
     */
    public function effectiveBomFor(MfgItem $item, ?string $date = null): ?MfgBom;

    /**
     * Explodes a BOM over all levels for a parent quantity: each row has its level, the quantity required (scrap and
     * yield included) and whether it is a phantom or a manufactured subassembly with its own effective BOM.
     *
     * @return list<array{component_item_id: string, component_item_code: string, component_name: string, bom_component_id: string, parent_item_id: string, level: int, quantity_per: string, scrap_percentage: string, yield_percentage: string, effective_quantity: string, uom: string, is_phantom: bool, has_bom: bool, issue_method: string, operation_sequence: int|null}>
     */
    public function explodeBom(MfgBom $bom, string $parentQuantity = '1', ?string $date = null): array;

    /**
     * Components to issue to a production order of the BOM: phantoms are exploded through, stocked components
     * (subassemblies included) stay as they are.
     *
     * @return list<array{component_item_id: string, component_item_code: string, component_name: string, bom_component_id: string, parent_item_id: string, level: int, quantity_per: string, scrap_percentage: string, yield_percentage: string, effective_quantity: string, uom: string, is_phantom: bool, has_bom: bool, issue_method: string, operation_sequence: int|null}>
     */
    public function componentRequirements(MfgBom $bom, string $parentQuantity, ?string $date = null): array;

    /**
     * BOM component lines that use the item, with their BOM revision.
     *
     * @return Collection<int, MfgBomComponent>
     */
    public function whereUsed(MfgItem $item): Collection;
}
