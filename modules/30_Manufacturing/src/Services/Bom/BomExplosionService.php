<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\Bom;

use Carbon\CarbonImmutable;
use Modules\Manufacturing\Contracts\BillOfMaterialsServiceInterface;
use Modules\Manufacturing\Contracts\BomExplosionServiceInterface;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgBomComponent;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Support\Money;

/**
 * Single and multi-level explosion of a BOM for a quantity and date. Proportional scrap and yield compound from level
 * to level (the gross need of a sub-assembly is the quantity its own BOM is exploded for), the fixed setup waste of
 * every line is added once per run, phantom assemblies are replaced by their components and every line carries the
 * lead-time offset at which it must be available before the parent is due.
 */
final readonly class BomExplosionService implements BomExplosionServiceInterface
{
    /** Deeper structures than this are refused rather than exploded. */
    private const int MaxDepth = 50;

    public function __construct(private BillOfMaterialsServiceInterface $boms) {}

    /**
     * {@inheritDoc}
     */
    public function explode(MfgBom $bom, string $quantity, ?string $date = null, bool $multiLevel = true, bool $flattenPhantoms = true): array
    {
        $day = CarbonImmutable::parse($date ?? now())->toDateString();
        $root = MfgItem::query()->where('tenant_id', $bom->tenant_id)->find($bom->mfg_item_id);
        $rootLead = $root instanceof MfgItem ? $root->lead_time_days : 0;

        $lines = $this->walk($bom, Money::of($quantity), $day, 1, $rootLead, [(string) $bom->mfg_item_id => true], $multiLevel, $flattenPhantoms, false, [(string) $bom->bom_number]);

        $total = $rootLead;
        foreach ($lines as $line) {
            $total = max($total, $line['lead_time_offset_days'] + ($line['has_bom'] ? 0 : $line['item_lead_time_days']));
        }

        return [
            'bom_id' => (string) $bom->id,
            'quantity' => Money::qty($quantity),
            'date' => $day,
            'multi_level' => $multiLevel,
            'flatten_phantoms' => $flattenPhantoms,
            'total_lead_time_days' => $total,
            'lines' => $lines,
        ];
    }

    /**
     * @param  array<string, bool>  $path  items on the way down, to refuse circular structures
     * @param  list<string>  $trail  codes of the assemblies on the way down
     * @return list<array<string, mixed>>
     */
    private function walk(MfgBom $bom, string $parentQuantity, string $day, int $level, int $leadOffset, array $path, bool $multiLevel, bool $flatten, bool $viaPhantom, array $trail): array
    {
        if ($level > self::MaxDepth) {
            throw new ManufacturingOperationException('The BOM structure is deeper than '.self::MaxDepth.' levels.', 'components');
        }

        $rows = [];
        $ratio = Money::div($parentQuantity, Money::isPositive($bom->base_quantity) ? $bom->base_quantity : '1');

        foreach (MfgBomComponent::query()->with('componentItem')->where('bom_id', $bom->id)->orderBy('sequence')->get() as $line) {
            $item = $line->componentItem;
            if (! $item instanceof MfgItem) {
                continue;
            }
            if (isset($path[(string) $item->id])) {
                throw new ManufacturingOperationException("Item [{$item->item_code}] is a component of itself through BOM [{$bom->bom_number}].", 'components');
            }

            $net = BomLineQuantity::net($line, $ratio);
            $variable = BomLineQuantity::variable($line, $ratio);
            $fixed = BomLineQuantity::fixed($line);
            $gross = Money::add($variable, $fixed);
            $subBom = $this->boms->effectiveBomFor($item, $day);
            $isPhantom = $line->is_phantom || $item->isPhantom() || ($subBom instanceof MfgBom && $subBom->isPhantom());
            $flattened = $flatten && $isPhantom && $subBom instanceof MfgBom;

            if (! $flattened) {
                $rows[] = [
                    'level' => $level,
                    'component_item_id' => (string) $item->id,
                    'component_item_code' => $item->item_code,
                    'component_name' => $item->name,
                    'parent_item_id' => (string) $bom->mfg_item_id,
                    'bom_component_id' => (string) $line->id,
                    'net_quantity' => Money::qty($net),
                    'variable_waste_quantity' => Money::qty(Money::sub($variable, $net)),
                    'fixed_waste_quantity' => Money::qty($fixed),
                    'scrap_quantity' => Money::qty(Money::sub($gross, $net)),
                    'effective_quantity' => Money::qty($gross),
                    'uom' => $line->uom,
                    'is_phantom' => $isPhantom,
                    'via_phantom' => $viaPhantom,
                    'has_bom' => $subBom instanceof MfgBom,
                    'issue_method' => $line->issue_method,
                    'operation_sequence' => $line->operation_sequence,
                    'substitute_item_ids' => $line->substitute_item_ids ?? [],
                    'item_lead_time_days' => $item->lead_time_days,
                    'lead_time_offset_days' => $leadOffset,
                    'path' => [...$trail, $item->item_code],
                ];
            }

            if (($multiLevel || $flattened) && $subBom instanceof MfgBom) {
                $childOffset = $flattened ? $leadOffset : $leadOffset + $item->lead_time_days;
                $children = $this->walk($subBom, $gross, $day, $flattened ? $level : $level + 1, $childOffset, [...$path, (string) $item->id => true], $multiLevel, $flatten, $viaPhantom || $flattened, $flattened ? $trail : [...$trail, $item->item_code]);
                array_push($rows, ...$children);
            }
        }

        return $rows;
    }
}
