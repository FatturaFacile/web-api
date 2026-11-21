<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\Bom;

use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Manufacturing\Events\BomComponentReplacedEvent;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgBomComponent;
use Modules\Manufacturing\Models\MfgItem;

/**
 * Replaces an obsolete or unavailable component by a substitute across every released BOM that uses it. Each affected
 * BOM gets its own proposed revision and engineering change (audit trail and approval per product); the substitute
 * takes over the quantity, scrap, waste and operation of the replaced line. All or nothing: a BOM whose revision cannot
 * be built (a circular reference, say) stops the whole replacement.
 */
final readonly class BomMassReplaceService
{
    public function __construct(
        private BomRevisionService $revisions,
        private EventDispatcher $dispatcher,
    ) {}

    /**
     * The released BOMs that use the item.
     *
     * @return Collection<int, MfgBom>
     */
    public function affected(string $tenantId, string $obsoleteItemId): Collection
    {
        return MfgBom::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereIn('id', MfgBomComponent::query()->where('tenant_id', $tenantId)->where('component_item_id', $obsoleteItemId)->select('bom_id'))
            ->orderBy('bom_number')
            ->get();
    }

    /**
     * Replaces the component in every released BOM, or only reports what would change.
     *
     * @return array{obsolete_item_id: string, substitute_item_id: string, dry_run: bool, replaced: list<array{bom_id: string, bom_number: string, revision: string, new_bom_id: string|null, new_revision: string|null, eco_id: string|null, eco_number: string|null}>, skipped: list<array{bom_id: string, bom_number: string, reason: string}>}
     */
    public function replace(string $tenantId, string $obsoleteItemId, string $substituteItemId, string $reason, ?string $effectiveFrom, bool $dryRun, ?string $actorUserId): array
    {
        $obsolete = MfgItem::query()->where('tenant_id', $tenantId)->findOrFail($obsoleteItemId);
        $substitute = MfgItem::query()->where('tenant_id', $tenantId)->findOrFail($substituteItemId);

        if ((string) $obsolete->id === (string) $substitute->id) {
            throw new ManufacturingOperationException('The substitute must be a different item.', 'substitute_item_id');
        }
        if ($obsolete->uom !== $substitute->uom) {
            throw new ManufacturingOperationException("Substitute [{$substitute->item_code}] has unit {$substitute->uom}, [{$obsolete->item_code}] has {$obsolete->uom}.", 'substitute_item_id');
        }

        return DB::transaction(function () use ($tenantId, $obsolete, $substitute, $reason, $effectiveFrom, $dryRun, $actorUserId): array {
            $replaced = [];
            $skipped = [];
            $bomIds = [];
            $ecoIds = [];

            foreach ($this->affected($tenantId, (string) $obsolete->id) as $bom) {
                $lines = $bom->components()->get()->keyBy('component_item_id');
                $line = $lines->get((string) $obsolete->id);
                if (! $line instanceof MfgBomComponent) {
                    continue;
                }
                if ($lines->has((string) $substitute->id)) {
                    $skipped[] = ['bom_id' => (string) $bom->id, 'bom_number' => $bom->bom_number, 'reason' => 'already_contains_substitute'];

                    continue;
                }
                if ((string) $bom->mfg_item_id === (string) $substitute->id) {
                    $skipped[] = ['bom_id' => (string) $bom->id, 'bom_number' => $bom->bom_number, 'reason' => 'substitute_is_the_product'];

                    continue;
                }

                if ($dryRun) {
                    $replaced[] = ['bom_id' => (string) $bom->id, 'bom_number' => $bom->bom_number, 'revision' => $bom->revision, 'new_bom_id' => null, 'new_revision' => null, 'eco_id' => null, 'eco_number' => null];

                    continue;
                }

                $result = $this->revisions->revise($bom, $reason, "Replace {$obsolete->item_code} with {$substitute->item_code} in {$bom->bom_number}", null, $effectiveFrom, [
                    ['action' => 'remove', 'component_item_id' => (string) $obsolete->id],
                    [
                        'action' => 'add',
                        'component_item_id' => (string) $substitute->id,
                        'sequence' => $line->sequence,
                        'quantity_per' => $line->quantity_per,
                        'scrap_percentage' => $line->scrap_percentage,
                        'yield_percentage' => $line->yield_percentage,
                        'fixed_scrap_quantity' => $line->fixed_scrap_quantity,
                        'is_phantom' => $line->is_phantom,
                        'issue_method' => $line->issue_method,
                        'operation_sequence' => $line->operation_sequence,
                        'substitute_item_ids' => array_values(array_filter($line->substitute_item_ids ?? [], static fn (string $id): bool => $id !== (string) $substitute->id)),
                        'notes' => $line->notes,
                    ],
                ], $actorUserId);

                $bomIds[] = (string) $result['bom']->id;
                $ecoIds[] = (string) $result['eco']->id;
                $replaced[] = ['bom_id' => (string) $bom->id, 'bom_number' => $bom->bom_number, 'revision' => $bom->revision, 'new_bom_id' => (string) $result['bom']->id, 'new_revision' => $result['bom']->revision, 'eco_id' => (string) $result['eco']->id, 'eco_number' => $result['eco']->eco_number];
            }

            if (! $dryRun && $bomIds !== []) {
                $this->dispatcher->publishAndDispatch(new BomComponentReplacedEvent(
                    obsoleteItemId: (string) $obsolete->id,
                    substituteItemId: (string) $substitute->id,
                    proposedBomIds: $bomIds,
                    engineeringChangeOrderIds: $ecoIds,
                    tenantId: $tenantId
                ));
            }

            return ['obsolete_item_id' => (string) $obsolete->id, 'substitute_item_id' => (string) $substitute->id, 'dry_run' => $dryRun, 'replaced' => $replaced, 'skipped' => $skipped];
        });
    }
}
