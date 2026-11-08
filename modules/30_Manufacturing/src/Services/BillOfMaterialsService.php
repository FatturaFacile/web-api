<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services;

use Carbon\CarbonImmutable;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Manufacturing\Contracts\BillOfMaterialsServiceInterface;
use Modules\Manufacturing\DTOs\CreateBomDTO;
use Modules\Manufacturing\Events\BOMCreatedEvent;
use Modules\Manufacturing\Events\BOMRevisedEvent;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgBomComponent;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Support\Money;

/**
 * Bills of materials: draft revisions, release with effective dates (the previous revision is superseded the day
 * before), revisions copied from an existing one, multi-level explosion with scrap and yield, recursion checks and
 * where-used.
 */
final readonly class BillOfMaterialsService implements BillOfMaterialsServiceInterface
{
    /** Deeper BOM structures than this are refused rather than exploded. */
    private const int MaxDepth = 25;

    public function __construct(private EventDispatcher $dispatcher) {}

    /**
     * {@inheritDoc}
     */
    public function createBom(CreateBomDTO $dto, ?string $actorUserId = null): MfgBom
    {
        return DB::transaction(function () use ($dto): MfgBom {
            $item = MfgItem::query()->where('tenant_id', $dto->tenantId)->findOrFail($dto->mfgItemId);
            $bomNumber = $dto->bomNumber ?? 'BOM-'.strtoupper($item->item_code);

            if (MfgBom::query()->where('tenant_id', $dto->tenantId)->where('bom_number', $bomNumber)->where('revision', $dto->revision)->exists()) {
                throw new ManufacturingOperationException("BOM [{$bomNumber}] already has revision [{$dto->revision}].", 'revision');
            }

            $version = (int) MfgBom::query()->where('tenant_id', $dto->tenantId)->where('bom_number', $bomNumber)->max('version') + 1;

            $bom = MfgBom::create([
                'tenant_id' => $dto->tenantId,
                'bom_number' => $bomNumber,
                'mfg_item_id' => $item->id,
                'name' => $dto->name,
                'revision' => $dto->revision,
                'version' => $version,
                'bom_type' => $dto->bomType,
                'status' => 'draft',
                'base_quantity' => Money::qty($dto->baseQuantity),
                'uom' => $dto->uom,
                'effective_from' => $dto->effectiveFrom,
                'effective_to' => $dto->effectiveTo,
                'is_locked' => false,
                'notes' => $dto->notes,
            ]);

            $this->writeComponents($bom, $dto->components);

            $this->dispatcher->publishAndDispatch(new BOMCreatedEvent(
                bomId: (string) $bom->id,
                bomNumber: $bom->bom_number,
                mfgItemId: $bom->mfg_item_id,
                revision: $bom->revision,
                tenantId: $dto->tenantId
            ));

            return $bom->load('components.componentItem');
        });
    }

    /**
     * {@inheritDoc}
     */
    public function updateDraft(MfgBom $bom, array $data, ?string $actorUserId = null): MfgBom
    {
        return DB::transaction(function () use ($bom, $data): MfgBom {
            $locked = MfgBom::query()->whereKey($bom->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isDraft()) {
                throw new ManufacturingOperationException("Revision [{$locked->revision}] of BOM [{$locked->bom_number}] is {$locked->status}: only drafts can be changed, revise it instead.", 'status');
            }

            $header = array_intersect_key($data, array_flip(['name', 'bom_type', 'uom', 'effective_from', 'effective_to', 'notes']));
            if (array_key_exists('base_quantity', $data)) {
                $header['base_quantity'] = Money::qty((string) $data['base_quantity']);
            }
            $locked->update($header);

            if (isset($data['components']) && is_array($data['components'])) {
                MfgBomComponent::query()->where('bom_id', $locked->id)->delete();
                $this->writeComponents($locked, array_values($data['components']));
            }

            return $locked->load('components.componentItem');
        });
    }

    /**
     * {@inheritDoc}
     */
    public function activate(MfgBom $bom, ?string $actorUserId = null): MfgBom
    {
        return DB::transaction(function () use ($bom, $actorUserId): MfgBom {
            $locked = MfgBom::query()->whereKey($bom->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isDraft()) {
                throw new ManufacturingOperationException("Revision [{$locked->revision}] of BOM [{$locked->bom_number}] is already {$locked->status}.", 'status');
            }
            if (! MfgBomComponent::query()->where('bom_id', $locked->id)->exists()) {
                throw new ManufacturingOperationException('A BOM without components cannot be released.', 'components');
            }

            $item = MfgItem::query()->whereKey($locked->mfg_item_id)->lockForUpdate()->firstOrFail();
            $from = CarbonImmutable::parse($locked->effective_from ?? now())->startOfDay();
            $this->assertNotRecursive($locked, $from->toDateString());

            $others = MfgBom::query()
                ->where('tenant_id', $locked->tenant_id)
                ->where('mfg_item_id', $locked->mfg_item_id)
                ->whereKeyNot($locked->id)
                ->whereIn('status', MfgBom::EffectiveStatuses)
                ->lockForUpdate()
                ->get();

            foreach ($others as $other) {
                $otherFrom = $other->effective_from !== null ? CarbonImmutable::parse($other->effective_from) : null;
                $otherTo = $other->effective_to !== null ? CarbonImmutable::parse($other->effective_to) : null;

                if ($otherTo !== null && $otherTo->lt($from)) {
                    continue;
                }
                if ($otherFrom !== null && $otherFrom->gte($from)) {
                    throw new ManufacturingOperationException("Revision [{$other->revision}] of BOM [{$other->bom_number}] is already effective from {$otherFrom->toDateString()}: choose a later effective date.", 'effective_from');
                }

                $other->update(['effective_to' => $from->subDay()->toDateString(), 'status' => 'superseded']);
            }

            $locked->update([
                'status' => 'active',
                'effective_from' => $from->toDateString(),
                'approved_by' => $actorUserId !== null && Str::isUuid($actorUserId) ? $actorUserId : null,
                'approved_at' => now(),
            ]);

            if ($from->lte(CarbonImmutable::today()) || $item->default_bom_id === null) {
                $item->update(['default_bom_id' => $locked->id]);
            }

            return $locked->load('components.componentItem');
        });
    }

    /**
     * {@inheritDoc}
     */
    public function reviseBom(MfgBom $bom, ?string $newRevision, string $reason, array $componentChanges = [], ?string $effectiveFrom = null, ?string $actorUserId = null): MfgBom
    {
        return DB::transaction(function () use ($bom, $newRevision, $reason, $componentChanges, $effectiveFrom): MfgBom {
            $source = MfgBom::query()->with('components')->whereKey($bom->id)->lockForUpdate()->firstOrFail();
            $revision = $newRevision ?? $this->nextRevision($source);

            if (MfgBom::query()->where('tenant_id', $source->tenant_id)->where('bom_number', $source->bom_number)->where('revision', $revision)->exists()) {
                throw new ManufacturingOperationException("BOM [{$source->bom_number}] already has revision [{$revision}].", 'new_revision');
            }

            $components = $source->components->map(static fn (MfgBomComponent $component): array => [
                'component_item_id' => $component->component_item_id,
                'sequence' => $component->sequence,
                'quantity_per' => $component->quantity_per,
                'scrap_percentage' => $component->scrap_percentage,
                'yield_percentage' => $component->yield_percentage,
                'is_phantom' => $component->is_phantom,
                'issue_method' => $component->issue_method,
                'operation_sequence' => $component->operation_sequence,
                'notes' => $component->notes,
            ])->keyBy('component_item_id')->all();

            foreach ($componentChanges as $change) {
                $itemId = (string) ($change['component_item_id'] ?? '');
                $action = (string) ($change['action'] ?? 'update');

                if ($action === 'remove') {
                    if (! isset($components[$itemId])) {
                        throw new ManufacturingOperationException("The component [{$itemId}] to remove is not in the BOM.", 'components');
                    }
                    unset($components[$itemId]);

                    continue;
                }
                if ($action === 'update' && ! isset($components[$itemId])) {
                    throw new ManufacturingOperationException("The component [{$itemId}] to update is not in the BOM.", 'components');
                }
                if ($action === 'add' && isset($components[$itemId])) {
                    throw new ManufacturingOperationException("The component [{$itemId}] to add is already in the BOM.", 'components');
                }

                $fields = array_intersect_key($change, array_flip(['quantity_per', 'sequence', 'scrap_percentage', 'yield_percentage', 'is_phantom', 'issue_method', 'operation_sequence', 'notes']));
                $components[$itemId] = [...($components[$itemId] ?? ['component_item_id' => $itemId]), ...$fields];
            }

            if ($components === []) {
                throw new ManufacturingOperationException('The revision would have no components left.', 'components');
            }

            $version = (int) MfgBom::query()->where('tenant_id', $source->tenant_id)->where('bom_number', $source->bom_number)->max('version') + 1;
            $draft = MfgBom::create([
                'tenant_id' => $source->tenant_id,
                'bom_number' => $source->bom_number,
                'mfg_item_id' => $source->mfg_item_id,
                'name' => $source->name,
                'revision' => $revision,
                'version' => $version,
                'bom_type' => $source->bom_type,
                'status' => 'draft',
                'base_quantity' => $source->base_quantity,
                'uom' => $source->uom,
                'effective_from' => $effectiveFrom,
                'is_locked' => false,
                'supersedes_bom_id' => $source->id,
                'notes' => "Revision {$revision} of {$source->revision}: {$reason}",
            ]);

            /** @var list<array{component_item_id: string, quantity_per: string|int|float, sequence?: int|null, scrap_percentage?: string|int|float|null, yield_percentage?: string|int|float|null, is_phantom?: bool|null, issue_method?: string|null, operation_sequence?: int|null, notes?: string|null}> $rows */
            $rows = array_values($components);
            $this->writeComponents($draft, $rows);

            $this->dispatcher->publishAndDispatch(new BOMRevisedEvent(
                bomId: (string) $draft->id,
                bomNumber: $draft->bom_number,
                newRevision: $revision,
                version: $version,
                tenantId: (string) $draft->tenant_id
            ));

            return $draft->load('components.componentItem');
        });
    }

    /**
     * {@inheritDoc}
     */
    public function effectiveBomFor(MfgItem $item, ?string $date = null): ?MfgBom
    {
        $day = CarbonImmutable::parse($date ?? now())->toDateString();

        return MfgBom::query()
            ->where('tenant_id', $item->tenant_id)
            ->where('mfg_item_id', $item->id)
            ->whereIn('status', MfgBom::EffectiveStatuses)
            ->where(static fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $day))
            ->where(static fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->orderByDesc('effective_from')
            ->orderByDesc('version')
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function explodeBom(MfgBom $bom, string $parentQuantity = '1', ?string $date = null): array
    {
        return $this->explode($bom, Money::of($parentQuantity), $date, 1, [(string) $bom->mfg_item_id => true], false);
    }

    /**
     * {@inheritDoc}
     */
    public function componentRequirements(MfgBom $bom, string $parentQuantity, ?string $date = null): array
    {
        return $this->explode($bom, Money::of($parentQuantity), $date, 1, [(string) $bom->mfg_item_id => true], true);
    }

    /**
     * {@inheritDoc}
     */
    public function whereUsed(MfgItem $item): Collection
    {
        return MfgBomComponent::query()
            ->with('bom')
            ->where('tenant_id', $item->tenant_id)
            ->where('component_item_id', $item->id)
            ->get();
    }

    /**
     * @param  array<string, bool>  $path  items on the way down, to refuse recursion
     * @return list<array{component_item_id: string, component_item_code: string, component_name: string, bom_component_id: string, parent_item_id: string, level: int, quantity_per: string, scrap_percentage: string, yield_percentage: string, effective_quantity: string, uom: string, is_phantom: bool, has_bom: bool, issue_method: string, operation_sequence: int|null}>
     */
    private function explode(MfgBom $bom, string $parentQuantity, ?string $date, int $level, array $path, bool $phantomsOnly): array
    {
        if ($level > self::MaxDepth) {
            throw new ManufacturingOperationException('The BOM structure is deeper than '.self::MaxDepth.' levels.', 'components');
        }

        $rows = [];
        $components = MfgBomComponent::query()->with('componentItem')->where('bom_id', $bom->id)->orderBy('sequence')->get();
        $ratio = Money::div($parentQuantity, Money::isPositive($bom->base_quantity) ? $bom->base_quantity : '1');

        foreach ($components as $component) {
            $item = $component->componentItem;
            if (! $item instanceof MfgItem) {
                continue;
            }
            if (isset($path[(string) $item->id])) {
                throw new ManufacturingOperationException("Item [{$item->item_code}] is a component of itself through BOM [{$bom->bom_number}].", 'components');
            }

            $quantity = $this->componentQuantity($component, $ratio);
            $subBom = $this->effectiveBomFor($item, $date);
            $isPhantom = $component->is_phantom || $item->isPhantom();

            $row = [
                'component_item_id' => (string) $item->id,
                'component_item_code' => $item->item_code,
                'component_name' => $item->name,
                'bom_component_id' => (string) $component->id,
                'parent_item_id' => (string) $bom->mfg_item_id,
                'level' => $level,
                'quantity_per' => Money::round($component->quantity_per, 4),
                'scrap_percentage' => Money::round($component->scrap_percentage),
                'yield_percentage' => Money::round($component->yield_percentage),
                'effective_quantity' => Money::qty($quantity),
                'uom' => $component->uom,
                'is_phantom' => $isPhantom,
                'has_bom' => $subBom !== null,
                'issue_method' => $component->issue_method,
                'operation_sequence' => $component->operation_sequence,
            ];

            $explodeChildren = $subBom !== null && (! $phantomsOnly || $isPhantom);
            if (! $phantomsOnly || ! $isPhantom || $subBom === null) {
                $rows[] = $row;
            }
            if ($explodeChildren) {
                $children = $this->explode($subBom, $quantity, $date, $level + 1, [...$path, (string) $item->id => true], $phantomsOnly);
                foreach ($children as $child) {
                    $rows[] = $child;
                }
            }
        }

        return $rows;
    }

    /**
     * Quantity of a component for the parent ratio: quantity per, grossed up by scrap and divided by yield.
     */
    private function componentQuantity(MfgBomComponent $component, string $ratio): string
    {
        $gross = Money::mul(Money::mul($component->quantity_per, $ratio), Money::add('1', Money::div($component->scrap_percentage, '100')));
        $yield = Money::isPositive($component->yield_percentage) ? Money::div($component->yield_percentage, '100') : '1';

        return Money::div($gross, $yield);
    }

    /**
     * Refuses a BOM whose explosion reaches its own item.
     */
    private function assertNotRecursive(MfgBom $bom, string $date): void
    {
        $this->explode($bom, '1', $date, 1, [(string) $bom->mfg_item_id => true], false);
    }

    /**
     * @param  list<array{component_item_id: string, quantity_per: string|int|float, sequence?: int|null, scrap_percentage?: string|int|float|null, yield_percentage?: string|int|float|null, is_phantom?: bool|null, issue_method?: string|null, operation_sequence?: int|null, notes?: string|null}>  $components
     */
    private function writeComponents(MfgBom $bom, array $components): void
    {
        $sequence = 10;
        foreach ($components as $component) {
            $item = MfgItem::query()->where('tenant_id', $bom->tenant_id)->findOrFail($component['component_item_id']);
            if ((string) $item->id === (string) $bom->mfg_item_id) {
                throw new ManufacturingOperationException("Item [{$item->item_code}] cannot be a component of its own BOM.", 'components');
            }

            MfgBomComponent::create([
                'tenant_id' => $bom->tenant_id,
                'bom_id' => $bom->id,
                'component_item_id' => $item->id,
                'sequence' => $component['sequence'] ?? $sequence,
                'quantity_per' => Money::qty($component['quantity_per']),
                'uom' => $item->uom,
                'scrap_percentage' => Money::round($component['scrap_percentage'] ?? '0'),
                'yield_percentage' => Money::round($component['yield_percentage'] ?? '100'),
                'is_phantom' => (bool) ($component['is_phantom'] ?? false),
                'issue_method' => $component['issue_method'] ?? 'manual',
                'operation_sequence' => $component['operation_sequence'] ?? null,
                'notes' => $component['notes'] ?? null,
            ]);
            $sequence += 10;
        }
    }

    /**
     * The revision after the given one: next letter, next number, or a dotted suffix.
     */
    private function nextRevision(MfgBom $bom): string
    {
        $current = (string) $bom->revision;

        if (preg_match('/^[A-Y]$/', $current) === 1) {
            return chr(ord($current) + 1);
        }
        if (preg_match('/^\d+$/', $current) === 1) {
            return (string) ((int) $current + 1);
        }

        return $current.'.'.$bom->version;
    }
}
