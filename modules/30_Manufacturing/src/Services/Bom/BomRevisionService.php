<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\Bom;

use Illuminate\Support\Facades\DB;
use Modules\Manufacturing\Contracts\BillOfMaterialsServiceInterface;
use Modules\Manufacturing\DTOs\CreateBomDTO;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgEngineeringChangeOrder;
use Modules\Manufacturing\Services\EngineeringChangeService;

/**
 * Creates a new BOM draft, or clones a released BOM into a new revision tied to an engineering change order: the
 * revision is built (cycle detection runs on its structure), the structural diff against the released one is stored on
 * the change, and the change is submitted so the approval workflow decides. The proposal is released by the
 * implementation of the approved change, which ends the validity of the previous revision the day before.
 */
final readonly class BomRevisionService
{
    public function __construct(
        private BillOfMaterialsServiceInterface $boms,
        private BomDiffService $diffs,
        private EngineeringChangeService $changes,
    ) {}

    /**
     * A new BOM draft of an item, without engineering change (nothing is released yet).
     *
     * @param  list<array{component_item_id: string, quantity_per: string|int|float, sequence?: int|null, scrap_percentage?: string|int|float|null, yield_percentage?: string|int|float|null, is_phantom?: bool|null, issue_method?: string|null, operation_sequence?: int|null, fixed_scrap_quantity?: string|int|float|null, substitute_item_ids?: list<string>|null, notes?: string|null}>  $components
     * @param  array<string, mixed>  $header
     */
    public function create(string $tenantId, string $itemId, string $name, array $components, array $header, ?string $actorUserId): MfgBom
    {
        return $this->boms->createBom(new CreateBomDTO(
            tenantId: $tenantId,
            mfgItemId: $itemId,
            name: $name,
            components: $components,
            revision: (string) ($header['revision'] ?? 'A'),
            bomType: (string) ($header['bom_type'] ?? 'standard'),
            baseQuantity: (string) ($header['base_quantity'] ?? '1'),
            uom: (string) ($header['uom'] ?? 'PCS'),
            effectiveFrom: $header['effective_from'] ?? null,
            effectiveTo: $header['effective_to'] ?? null,
            notes: $header['notes'] ?? null,
            bomNumber: $header['bom_number'] ?? null,
        ), $actorUserId);
    }

    /**
     * Clones the released BOM into a proposed revision with the component changes and opens its engineering change.
     *
     * @param  list<array<string, mixed>>  $componentChanges
     * @return array{bom: MfgBom, eco: MfgEngineeringChangeOrder, diff: array<string, mixed>}
     */
    public function revise(MfgBom $source, string $reason, ?string $title, ?string $newRevision, ?string $effectiveFrom, array $componentChanges, ?string $actorUserId): array
    {
        if (! $source->isActive()) {
            throw new ManufacturingOperationException("Revision [{$source->revision}] of BOM [{$source->bom_number}] is {$source->status}: only a released revision is revised through an engineering change.", 'source_bom_id');
        }

        return DB::transaction(function () use ($source, $reason, $title, $newRevision, $effectiveFrom, $componentChanges, $actorUserId): array {
            $proposal = $this->boms->reviseBom($source, $newRevision, $reason, $componentChanges, $effectiveFrom, $actorUserId);
            $proposal->load('components');
            $diff = $this->diffs->between($source, $proposal);

            $eco = $this->changes->openForRevision(
                $source,
                $proposal,
                $diff,
                $title ?? "Revision {$proposal->revision} of {$source->bom_number}",
                $reason,
                $effectiveFrom,
                $componentChanges,
                $actorUserId
            );

            return ['bom' => $proposal->refresh()->load('components.componentItem'), 'eco' => $eco, 'diff' => $diff];
        });
    }
}
