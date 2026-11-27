<?php

declare(strict_types=1);

namespace Modules\Distribution\Services;

use Illuminate\Support\Collection;
use Modules\Distribution\Domain\Enums\AllocationMode;
use Modules\Distribution\Domain\Enums\WarehouseRole;
use Modules\Distribution\Models\SalesChannel;
use Modules\Distribution\Models\SalesChannelWarehouse;
use Modules\Distribution\Models\WarehouseProfile;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Support\Quantity;

/**
 * Real-time available-to-promise (ATP) per sales channel, per channel and warehouse, for any item.
 *
 * Per warehouse the physical on-hand minus the reservations is the free stock. The channels mapped to the warehouse draw on it in
 * a fixed order, so no unit is promised twice:
 *  1. fixed reserve: the quantity is ring-fenced for the channel and carved out first;
 *  2. percentage: the channel gets its share of what is left after the ring-fences;
 *  3. priority rank: channels in rank order take what is left (up to their fixed reserve quantity when one is set);
 *  4. shared pool: every shared channel sees all that is left.
 * `include_in_transit` adds the stock on its way to the warehouse to the channel's pool, `exclude_consignment` and `exclude_3pl`
 * keep segregated warehouses out, and the safety buffer is held back at the end. ATP = max(0, allocated - buffer).
 */
final class ChannelAvailabilityService
{
    public function __construct(
        private readonly StockPositionService $positions,
        private readonly NetworkService $network,
    ) {}

    /**
     * @return array{stock_item: array{id: string, sku: string, name: string, uom: string}, channels: list<array<string, mixed>>}
     */
    public function forItem(StockItem $item, ?string $channelId = null): array
    {
        $computed = $this->compute([(string) $item->id])[(string) $item->id] ?? [];
        $channels = array_values(array_filter($computed, static fn (array $channel): bool => $channelId === null || $channel['sales_channel_id'] === $channelId));

        return [
            'stock_item' => ['id' => (string) $item->id, 'sku' => (string) $item->sku, 'name' => (string) $item->name, 'uom' => (string) $item->uom],
            'channels' => $channels,
        ];
    }

    /**
     * The ATP of every channel for a page of items.
     *
     * @return array{channels: list<array{id: string, code: string, name: string}>, rows: list<array<string, mixed>>, total: int}
     */
    public function matrix(int $page = 1, int $perPage = 25): array
    {
        $channels = SalesChannel::query()->where('is_active', true)->orderBy('priority')->orderBy('code')->get();
        $warehouseIds = SalesChannelWarehouse::query()->where('is_active', true)->pluck('warehouse_id')->unique()->values()->all();

        $itemIds = array_values(array_unique(array_map(
            static fn (string $key): string => explode('|', $key)[1],
            array_keys($this->positions->onHand($warehouseIds)),
        )));

        $items = StockItem::query()->whereIn('id', $itemIds)->orderBy('sku')->get();
        $slice = $items->forPage(max(1, $page), $perPage);
        $computed = $this->compute($slice->pluck('id')->map(static fn ($id): string => (string) $id)->all());

        $rows = $slice->map(static function (StockItem $item) use ($channels, $computed): array {
            $cells = [];

            foreach ($channels as $channel) {
                $result = collect($computed[(string) $item->id] ?? [])->firstWhere('sales_channel_id', (string) $channel->id);
                $cells[(string) $channel->id] = $result['atp'] ?? '0.0000';
            }

            return ['stock_item_id' => (string) $item->id, 'sku' => (string) $item->sku, 'name' => (string) $item->name, 'cells' => $cells];
        })->values()->all();

        return [
            'channels' => $channels->map(static fn (SalesChannel $channel): array => ['id' => (string) $channel->id, 'code' => (string) $channel->code, 'name' => (string) $channel->name])->values()->all(),
            'rows' => $rows,
            'total' => $items->count(),
        ];
    }

    /**
     * @param  list<string>  $itemIds
     * @return array<string, list<array<string, mixed>>> channel results per item
     */
    public function compute(array $itemIds): array
    {
        $rules = SalesChannelWarehouse::query()->where('is_active', true)
            ->whereHas('channel', static fn ($query) => $query->where('is_active', true))
            ->with(['channel', 'warehouse'])
            ->get();

        if ($rules->isEmpty() || $itemIds === []) {
            return [];
        }

        $warehouseIds = $rules->pluck('warehouse_id')->unique()->values()->all();
        $onHand = $this->positions->onHand($warehouseIds, $itemIds);
        $inbound = $this->positions->inTransitInbound($warehouseIds, $itemIds);
        $roles = $this->network->roles(Warehouse::query()->whereIn('id', $warehouseIds)->get());
        $profiles = WarehouseProfile::query()->whereIn('warehouse_id', $warehouseIds)->get()->keyBy('warehouse_id');
        $byWarehouse = $rules->groupBy('warehouse_id');
        $result = [];

        foreach ($itemIds as $itemId) {
            $allocations = [];

            foreach ($byWarehouse as $warehouseId => $warehouseRules) {
                $key = StockPositionService::key((string) $warehouseId, $itemId);
                $stock = $onHand[$key] ?? ['on_hand' => '0.0000', 'reserved' => '0.0000', 'available' => '0.0000'];
                $incoming = $inbound[$key] ?? '0.0000';
                $eligible = $warehouseRules->filter(fn (SalesChannelWarehouse $rule): bool => $this->eligible($rule, $roles[(string) $warehouseId] ?? WarehouseRole::Internal, $profiles->get($warehouseId)));

                foreach ($this->allocate($eligible, $stock['available'], $incoming) as $ruleId => $allocated) {
                    $allocations[$ruleId] = $allocated + ['on_hand' => $stock['on_hand'], 'reserved' => $stock['reserved'], 'in_transit_inbound' => $incoming];
                }
            }

            $result[$itemId] = $this->channelRows($rules, $allocations);
        }

        return $result;
    }

    private function eligible(SalesChannelWarehouse $rule, WarehouseRole $role, ?WarehouseProfile $profile): bool
    {
        if ($profile?->is_blocked === true || $role === WarehouseRole::Transit) {
            return false;
        }

        if ($role->isConsignment() && $rule->exclude_consignment) {
            return false;
        }

        if ($role === WarehouseRole::ThirdParty3pl && $rule->exclude_3pl) {
            return false;
        }

        return ! ($role->isPlannable() && $profile?->include_in_free_stock === false);
    }

    /**
     * @param  Collection<int, SalesChannelWarehouse>  $rules  eligible rules of one warehouse
     * @return array<string, array{ring_fenced: string, atp: string, buffer: string}>
     */
    private function allocate(Collection $rules, string $available, string $inbound): array
    {
        $ordered = $rules->sortBy([fn (SalesChannelWarehouse $a, SalesChannelWarehouse $b): int => $a->channel->priority <=> $b->channel->priority, fn (SalesChannelWarehouse $a, SalesChannelWarehouse $b): int => $a->priority_rank <=> $b->priority_rank]);
        $shares = [];
        $remaining = $available;

        // 1. Ring-fenced reserves.
        foreach ($ordered->where('allocation_mode', AllocationMode::FixedReserve) as $rule) {
            $claim = Quantity::min((string) $rule->fixed_reserve_qty, $remaining);
            $shares[(string) $rule->id] = $claim;
            $remaining = Quantity::sub($remaining, $claim);
        }

        // 2. Percentages of what is left after the ring-fences.
        $afterFences = $remaining;

        foreach ($ordered->where('allocation_mode', AllocationMode::Percentage) as $rule) {
            $ratio = bcdiv((string) ($rule->allocation_percentage ?? '0'), '100', 8);
            $pool = Quantity::add($afterFences, $rule->include_in_transit ? $inbound : '0');
            $shares[(string) $rule->id] = Quantity::mul($pool, $ratio);
            $remaining = Quantity::max('0', Quantity::sub($remaining, Quantity::mul($afterFences, $ratio)));
        }

        // 3. Priority ranks take what is left, in rank order.
        foreach ($ordered->where('allocation_mode', AllocationMode::PriorityRank) as $rule) {
            $cap = Quantity::isPositive((string) $rule->fixed_reserve_qty) ? Quantity::min((string) $rule->fixed_reserve_qty, $remaining) : $remaining;
            $shares[(string) $rule->id] = Quantity::add($cap, $rule->include_in_transit ? $inbound : '0');
            $remaining = Quantity::sub($remaining, $cap);
        }

        // 4. Shared pools see everything that is left.
        foreach ($ordered->where('allocation_mode', AllocationMode::SharedPool) as $rule) {
            $shares[(string) $rule->id] = Quantity::add($remaining, $rule->include_in_transit ? $inbound : '0');
        }

        $allocations = [];

        foreach ($rules as $rule) {
            $share = $shares[(string) $rule->id] ?? '0.0000';
            $buffer = Quantity::of((string) $rule->safety_buffer_qty);
            $allocations[(string) $rule->id] = [
                'ring_fenced' => Quantity::of($share),
                'buffer' => $buffer,
                'atp' => Quantity::max('0', Quantity::sub($share, $buffer)),
            ];
        }

        return $allocations;
    }

    /**
     * @param  Collection<int, SalesChannelWarehouse>  $rules
     * @param  array<string, array<string, string>>  $allocations
     * @return list<array<string, mixed>>
     */
    private function channelRows(Collection $rules, array $allocations): array
    {
        $rows = [];

        foreach ($rules->groupBy('sales_channel_id') as $channelRules) {
            /** @var SalesChannel $channel */
            $channel = $channelRules->first()->channel;
            $totals = ['on_hand' => '0', 'reserved' => '0', 'in_transit_inbound' => '0', 'ring_fenced' => '0', 'safety_buffer' => '0', 'atp' => '0'];
            $warehouses = [];

            foreach ($channelRules as $rule) {
                $allocation = $allocations[(string) $rule->id] ?? null;

                if ($allocation === null) {
                    continue;
                }

                foreach (['on_hand', 'reserved', 'in_transit_inbound', 'ring_fenced', 'atp'] as $field) {
                    $totals[$field] = Quantity::add($totals[$field], $allocation[$field]);
                }

                $totals['safety_buffer'] = Quantity::add($totals['safety_buffer'], $allocation['buffer']);
                $warehouses[] = [
                    'warehouse_id' => (string) $rule->warehouse_id,
                    'code' => (string) $rule->warehouse?->code,
                    'name' => (string) $rule->warehouse?->name,
                    'on_hand' => $allocation['on_hand'],
                    'reserved' => $allocation['reserved'],
                    'in_transit_inbound' => $allocation['in_transit_inbound'],
                    'ring_fenced' => $allocation['ring_fenced'],
                    'atp' => $allocation['atp'],
                ];
            }

            $rows[] = ['sales_channel_id' => (string) $channel->id, 'code' => (string) $channel->code, 'name' => (string) $channel->name, 'priority' => $channel->priority]
                + array_map(static fn (string $value): string => Quantity::of($value), $totals)
                + ['warehouses' => $warehouses];
        }

        usort($rows, static fn (array $left, array $right): int => [$left['priority'], $left['code']] <=> [$right['priority'], $right['code']]);

        return array_map(static function (array $row): array {
            unset($row['priority']);

            return $row;
        }, $rows);
    }
}
