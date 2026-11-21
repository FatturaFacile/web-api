<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\Bom;

use Illuminate\Support\Facades\DB;
use Modules\Manufacturing\Contracts\CoProductAllocationServiceInterface;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgBomCoProduct;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Support\Money;

/**
 * Co-products and by-products of a BOM revision and the split of the production cost between them and the primary
 * output. The shares are accounting data (product costing): they must be non-negative, the products distinct and not
 * the primary output, and together they may not exceed 100%.
 */
final class CoProductAllocationService implements CoProductAllocationServiceInterface
{
    /**
     * {@inheritDoc}
     */
    public function replace(MfgBom $bom, array $lines): MfgBom
    {
        return DB::transaction(function () use ($bom, $lines): MfgBom {
            $locked = MfgBom::query()->whereKey($bom->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isDraft()) {
                throw new ManufacturingOperationException("Revision [{$locked->revision}] of BOM [{$locked->bom_number}] is {$locked->status}: only drafts can be changed, revise it instead.", 'status');
            }

            $this->assertValid($locked, $lines);

            MfgBomCoProduct::query()->where('bom_id', $locked->id)->delete();
            $this->write($locked, $lines);

            return $locked->load('coProducts');
        });
    }

    /**
     * Copies the co/by-products of a revision to another one (a new draft revision).
     */
    public function copy(MfgBom $from, MfgBom $to): void
    {
        $lines = $from->coProducts()->get()->map(static fn (MfgBomCoProduct $line): array => [
            'product_item_id' => $line->product_item_id,
            'product_type' => $line->product_type,
            'output_ratio_qty' => $line->output_ratio_qty,
            'cost_allocation_percentage' => $line->cost_allocation_percentage,
        ])->all();

        $this->write($to, $lines);
    }

    /**
     * {@inheritDoc}
     */
    public function allocation(MfgBom $bom): array
    {
        $lines = $bom->coProducts()->get();
        $shared = $lines->reduce(static fn (string $sum, MfgBomCoProduct $line): string => Money::add($sum, $line->cost_allocation_percentage), '0');

        return [
            'bom_id' => (string) $bom->id,
            'primary_item_id' => (string) $bom->mfg_item_id,
            'primary_percentage' => Money::round(Money::sub('100', $shared)),
            'co_products' => $lines->map(static fn (MfgBomCoProduct $line): array => [
                'product_item_id' => (string) $line->product_item_id,
                'product_type' => $line->product_type,
                'output_ratio_qty' => Money::qty($line->output_ratio_qty),
                'cost_allocation_percentage' => Money::round($line->cost_allocation_percentage),
            ])->values()->all(),
            'total_percentage' => '100.00',
        ];
    }

    /**
     * @param  list<array{product_item_id: string, product_type?: string|null, output_ratio_qty: string|int|float, cost_allocation_percentage: string|int|float}>  $lines
     */
    private function assertValid(MfgBom $bom, array $lines): void
    {
        $seen = [];
        $total = '0';

        foreach ($lines as $line) {
            $itemId = (string) $line['product_item_id'];
            $item = MfgItem::query()->where('tenant_id', $bom->tenant_id)->findOrFail($itemId);

            if ($itemId === (string) $bom->mfg_item_id) {
                throw new ManufacturingOperationException("Item [{$item->item_code}] is the primary output of the BOM, not a co-product.", 'co_products');
            }
            if (isset($seen[$itemId])) {
                throw new ManufacturingOperationException("Item [{$item->item_code}] is listed more than once as co-product.", 'co_products');
            }
            $seen[$itemId] = true;

            if (! Money::isPositive($line['output_ratio_qty'])) {
                throw new ManufacturingOperationException("The output quantity of co-product [{$item->item_code}] must be positive.", 'co_products');
            }
            if (Money::isNegative($line['cost_allocation_percentage'])) {
                throw new ManufacturingOperationException("The cost share of co-product [{$item->item_code}] cannot be negative.", 'co_products');
            }

            $total = Money::add($total, $line['cost_allocation_percentage']);
        }

        if (Money::compare($total, '100') > 0) {
            throw new ManufacturingOperationException('The cost shares of the co/by-products total '.Money::round($total).'%: they cannot exceed 100% (the primary output carries the remainder).', 'co_products');
        }
    }

    /**
     * @param  list<array{product_item_id: string, product_type?: string|null, output_ratio_qty: string|int|float, cost_allocation_percentage: string|int|float}>  $lines
     */
    private function write(MfgBom $bom, array $lines): void
    {
        foreach ($lines as $line) {
            MfgBomCoProduct::create([
                'tenant_id' => $bom->tenant_id,
                'bom_id' => $bom->id,
                'product_item_id' => $line['product_item_id'],
                'product_type' => $line['product_type'] ?? 'co_product',
                'output_ratio_qty' => Money::qty($line['output_ratio_qty']),
                'cost_allocation_percentage' => Money::round($line['cost_allocation_percentage']),
            ]);
        }
    }
}
