<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\Models\DeliveryNote;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockPackage;
use Modules\Inventory\Models\StockPackageItem;
use Modules\Inventory\Models\StockPackingList;
use Modules\Inventory\Models\StockPickList;

/**
 * Turns what was picked into packages. Without instructions each item fills its own cartons, and the cartons their
 * pallets, following the item master; `packages` in the request describes a mixed packing instead. Weights go up to
 * the packing list and, when one is linked, to the delivery note.
 */
final class PackingListService
{
    public function __construct(
        private readonly PackagingCalculator $calculator
    ) {}

    /**
     * @param  list<array{package_type: string, tare_weight_kg?: mixed, items: list<array{stock_item_id: string, lot_id?: ?string, quantity: mixed}>}>|null  $packages
     */
    public function pack(StockPickList $pickList, string $userId, ?string $deliveryNoteId = null, ?array $packages = null): StockPackingList
    {
        return DB::transaction(function () use ($pickList, $userId, $deliveryNoteId, $packages): StockPackingList {
            $locked = StockPickList::query()->with('lines.item')->whereKey($pickList->id)->lockForUpdate()->firstOrFail();

            if (StockPackingList::query()->where('stock_pick_list_id', $locked->id)->exists()) {
                throw new StockRuleViolation("Pick list [{$locked->pick_list_number}] is already packed.");
            }

            $picked = $this->pickedByItemAndLot($locked);

            if ($picked->isEmpty()) {
                throw new StockRuleViolation("Nothing was picked on list [{$locked->pick_list_number}].");
            }

            $packingList = StockPackingList::create([
                'tenant_id' => $locked->tenant_id,
                'stock_pick_list_id' => $locked->id,
                'packing_number' => 'PACK-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
                'packed_by_user_id' => $userId,
                'packed_at' => now(),
                'delivery_note_id' => $deliveryNoteId,
            ]);

            $packages === null ? $this->packByMasterData($packingList, $picked) : $this->packAsInstructed($packingList, $picked, $packages);

            return $this->totalUp($packingList);
        });
    }

    /**
     * @param  Collection<int, array{item: StockItem, lot_id: ?string, quantity: string}>  $picked
     */
    private function packByMasterData(StockPackingList $packingList, Collection $picked): void
    {
        foreach ($picked as $line) {
            $item = $line['item'];
            $remaining = $line['quantity'];
            $unitsPerCarton = $item->units_per_carton;
            $pallet = null;
            $cartonsOnPallet = 0;

            if ($unitsPerCarton === null) {
                $this->addPackage($packingList, 'box', '0', [['item' => $item, 'lot_id' => $line['lot_id'], 'quantity' => $remaining]]);

                continue;
            }

            while (bccomp($remaining, '0', 4) > 0) {
                if ($item->cartons_per_pallet !== null && ($pallet === null || $cartonsOnPallet === $item->cartons_per_pallet)) {
                    $pallet = $this->addPackage($packingList, 'pallet', (string) ($item->pallet_tare_kg ?? '0'), []);
                    $cartonsOnPallet = 0;
                }

                $content = bccomp($remaining, (string) $unitsPerCarton, 4) < 0 ? $remaining : bcadd((string) $unitsPerCarton, '0', 4);
                $this->addPackage($packingList, 'carton', (string) ($item->carton_tare_kg ?? '0'), [['item' => $item, 'lot_id' => $line['lot_id'], 'quantity' => $content]], $pallet);
                $remaining = bcsub($remaining, $content, 4);
                $cartonsOnPallet++;
            }
        }
    }

    /**
     * @param  Collection<int, array{item: StockItem, lot_id: ?string, quantity: string}>  $picked
     * @param  list<array{package_type: string, tare_weight_kg?: mixed, items: list<array{stock_item_id: string, lot_id?: ?string, quantity: mixed}>}>  $packages
     */
    private function packAsInstructed(StockPackingList $packingList, Collection $picked, array $packages): void
    {
        $left = $picked->mapWithKeys(static fn (array $line): array => [$line['item']->id.'|'.$line['lot_id'] => $line]);

        foreach ($packages as $package) {
            $contents = [];

            foreach ($package['items'] as $content) {
                $key = $content['stock_item_id'].'|'.($content['lot_id'] ?? '');
                $line = $left->get($key);
                $quantity = bcadd((string) $content['quantity'], '0', 4);

                if ($line === null || bccomp($quantity, $line['quantity'], 4) > 0) {
                    throw new StockRuleViolation('A package holds more than was picked of an item.');
                }

                $line['quantity'] = bcsub($line['quantity'], $quantity, 4);
                $left->put($key, $line);
                $contents[] = ['item' => $line['item'], 'lot_id' => $line['lot_id'], 'quantity' => $quantity];
            }

            $this->addPackage($packingList, $package['package_type'], (string) ($package['tare_weight_kg'] ?? '0'), $contents);
        }

        if ($left->contains(static fn (array $line): bool => bccomp($line['quantity'], '0', 4) > 0)) {
            throw new StockRuleViolation('Some picked goods are in no package.');
        }
    }

    /**
     * @param  list<array{item: StockItem, lot_id: ?string, quantity: string}>  $contents
     */
    private function addPackage(StockPackingList $packingList, string $type, string $tare, array $contents, ?StockPackage $parent = null): StockPackage
    {
        $net = '0.000';

        $package = StockPackage::create([
            'tenant_id' => $packingList->tenant_id,
            'stock_packing_list_id' => $packingList->id,
            'parent_package_id' => $parent?->id,
            'package_number' => strtoupper($type[0]).'-'.strtoupper(Str::random(10)),
            'package_type' => $type,
            'tare_weight_kg' => $tare,
        ]);

        foreach ($contents as $content) {
            $weight = bcmul($content['quantity'], (string) ($content['item']->net_weight_kg ?? $content['item']->weight_kg ?? '0'), 3);
            $net = bcadd($net, $weight, 3);

            StockPackageItem::create([
                'tenant_id' => $packingList->tenant_id,
                'stock_package_id' => $package->id,
                'stock_item_id' => $content['item']->id,
                'lot_id' => $content['lot_id'],
                'quantity' => $content['quantity'],
                'net_weight_kg' => $weight,
            ]);
        }

        $package->update(['net_weight_kg' => $net, 'gross_weight_kg' => bcadd($net, $tare, 3)]);

        return $package;
    }

    /**
     * @return Collection<int, array{item: StockItem, lot_id: ?string, quantity: string}>
     */
    private function pickedByItemAndLot(StockPickList $pickList): Collection
    {
        return $pickList->lines
            ->filter(static fn ($line): bool => bccomp((string) $line->quantity_picked, '0', 4) > 0)
            ->groupBy(static fn ($line): string => $line->stock_item_id.'|'.$line->lot_id)
            ->map(static fn (Collection $lines): array => [
                'item' => StockItem::query()->findOrFail($lines->first()->stock_item_id),
                'lot_id' => $lines->first()->lot_id,
                'quantity' => $lines->reduce(static fn (string $sum, $line): string => bcadd($sum, (string) $line->quantity_picked, 4), '0'),
            ])
            ->values();
    }

    private function totalUp(StockPackingList $packingList): StockPackingList
    {
        $packages = StockPackage::query()->where('stock_packing_list_id', $packingList->id)->get();
        $topLevel = $packages->whereNull('parent_package_id');
        $net = $packages->reduce(static fn (string $sum, StockPackage $package): string => bcadd($sum, (string) $package->net_weight_kg, 3), '0');
        $tare = $packages->reduce(static fn (string $sum, StockPackage $package): string => bcadd($sum, (string) $package->tare_weight_kg, 3), '0');
        $gross = bcadd($net, $tare, 3);
        $volume = $this->volumeOf($packingList);

        $packingList->update([
            'total_packages' => $topLevel->count(),
            'total_weight_kg' => $gross,
            'net_weight_kg' => $net,
            'total_volume_m3' => $volume,
            'cartons_count' => $packages->where('package_type', 'carton')->count(),
            'pallets_count' => $packages->where('package_type', 'pallet')->count(),
        ]);

        if ($packingList->delivery_note_id !== null) {
            DeliveryNote::query()->whereKey($packingList->delivery_note_id)->update([
                'packages_count' => $topLevel->count(),
                'gross_weight_kg' => $gross,
                'net_weight_kg' => $net,
            ]);
        }

        return $packingList->load('packages.items');
    }

    private function volumeOf(StockPackingList $packingList): string
    {
        $contents = StockPackageItem::query()
            ->whereHas('package', static fn ($package) => $package->where('stock_packing_list_id', $packingList->id))
            ->with('item')
            ->get();

        return $contents->reduce(static fn (string $sum, StockPackageItem $content): string => bcadd($sum, bcmul((string) $content->quantity, (string) ($content->item?->volume_m3 ?? '0'), 5), 5), '0');
    }
}
