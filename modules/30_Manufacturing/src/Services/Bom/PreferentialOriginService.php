<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\Bom;

use Modules\Manufacturing\Contracts\BomExplosionServiceInterface;
use Modules\Manufacturing\Contracts\PreferentialOriginServiceInterface;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Support\Money;

/**
 * Preferential origin test: the multi-level BOM is exploded (phantoms flattened, waste included, because scrapped
 * material was paid for) and each material is valued at its standard cost. A sub-assembly that itself has originating
 * status counts as one originating material (cumulation), otherwise its own materials are tested. The product is
 * eligible for EUR.1 when the non-originating value does not exceed the threshold share of the ex-works price.
 */
final readonly class PreferentialOriginService implements PreferentialOriginServiceInterface
{
    public function __construct(private BomExplosionServiceInterface $explosion) {}

    /**
     * {@inheritDoc}
     */
    public function evaluate(MfgBom $bom, ?string $date = null, ?string $thresholdPercent = null): array
    {
        $product = MfgItem::query()->where('tenant_id', $bom->tenant_id)->findOrFail($bom->mfg_item_id);
        $quantity = Money::isPositive($bom->base_quantity) ? Money::of($bom->base_quantity) : '1';
        $exw = Money::mul($product->exw_price, $quantity);
        if (! Money::isPositive($exw)) {
            throw new ManufacturingOperationException("Item [{$product->item_code}] has no ex-works price: the origin test needs one.", 'exw_price');
        }

        $threshold = Money::of($thresholdPercent ?? self::DefaultThresholdPercent);
        $exploded = $this->explosion->explode($bom, $quantity, $date, true, true);

        /** @var array<string, MfgItem> $items */
        $items = MfgItem::query()
            ->where('tenant_id', $bom->tenant_id)
            ->whereIn('id', array_values(array_unique(array_map(static fn (array $line): string => (string) $line['component_item_id'], $exploded['lines']))))
            ->get()->keyBy('id')->all();

        $originatingAssemblies = [];
        foreach ($exploded['lines'] as $line) {
            $item = $items[(string) $line['component_item_id']] ?? null;
            if ($line['has_bom'] && $item instanceof MfgItem && $item->origin_status === 'originating') {
                $originatingAssemblies[(string) $line['component_item_code']] = true;
            }
        }

        $originating = '0';
        $nonOriginating = '0';
        $lines = [];
        foreach ($exploded['lines'] as $line) {
            $item = $items[(string) $line['component_item_id']] ?? null;
            if (! $item instanceof MfgItem) {
                continue;
            }

            // Materials inside an originating sub-assembly are covered by its status.
            $ancestors = array_slice($line['path'], 1, -1);
            if (array_filter($ancestors, static fn (string $code): bool => isset($originatingAssemblies[$code])) !== []) {
                continue;
            }

            $isOriginatingAssembly = $line['has_bom'] && isset($originatingAssemblies[(string) $line['component_item_code']]);
            if ($line['has_bom'] && ! $isOriginatingAssembly) {
                continue;
            }

            $unitCost = Money::isPositive($item->standard_cost) ? $item->standard_cost : $item->current_cost;
            $value = Money::mul($line['effective_quantity'], $unitCost);
            $isOriginating = $item->origin_status === 'originating';
            if ($isOriginating) {
                $originating = Money::add($originating, $value);
            } else {
                $nonOriginating = Money::add($nonOriginating, $value);
            }

            $lines[] = [
                'item_id' => (string) $item->id,
                'item_code' => $item->item_code,
                'quantity' => $line['effective_quantity'],
                'unit_cost' => Money::round($unitCost, 4),
                'value' => Money::round($value, 4),
                'originating' => $isOriginating,
                'path' => $line['path'],
            ];
        }

        $percentage = Money::round(Money::mul(Money::div($nonOriginating, $exw), '100'));

        return [
            'bom_id' => (string) $bom->id,
            'item_id' => (string) $product->id,
            'quantity' => Money::qty($quantity),
            'exw_price' => Money::round($exw, 4),
            'originating_value' => Money::round($originating, 4),
            'non_originating_value' => Money::round($nonOriginating, 4),
            'non_originating_percentage' => $percentage,
            'threshold_percentage' => Money::round($threshold),
            'eligible' => Money::compare($percentage, $threshold) <= 0,
            'lines' => $lines,
        ];
    }
}
