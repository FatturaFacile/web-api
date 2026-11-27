<?php

declare(strict_types=1);

namespace Modules\Distribution\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Distribution\Domain\Enums\AlertType;
use Modules\Distribution\Domain\Enums\BucketType;
use Modules\Distribution\Domain\Enums\ReplenishmentPolicy;
use Modules\Distribution\Domain\Enums\RunStatus;
use Modules\Distribution\Models\DrpAlert;
use Modules\Distribution\Models\DrpPlanLine;
use Modules\Distribution\Models\DrpRun;
use Modules\Distribution\Models\ReplenishmentRule;
use Modules\Distribution\Models\WarehouseProfile;
use Modules\Inventory\Support\Quantity;
use Throwable;

/**
 * Time-phased distribution requirements planning (multi-echelon).
 *
 * For every (warehouse, item) of the replenishment rules and for every time bucket it computes gross requirements (open sales
 * orders or the consumption forecast, whichever is higher, plus transfers already committed out of the warehouse plus the planned
 * releases of the warehouses it supplies), scheduled receipts (approved and in-transit transfers), the projected available
 * balance, the net requirement against safety stock and the planned order receipts and releases (offset backwards by the lead
 * time). The warehouses are planned from the stores up to the hubs, so the planned releases of a spoke roll up as gross
 * requirements of the hub that supplies it. Every run is stored as a versioned snapshot with its alerts.
 */
final class DrpPlanner
{
    public function __construct(
        private readonly StockPositionService $positions,
        private readonly DemandService $demand,
        private readonly NetworkService $network,
    ) {}

    /**
     * @param  list<string>  $warehouseIds  warehouses whose rows are stored (empty = all); the whole network is always planned
     * @param  list<string>  $itemIds
     */
    public function run(BucketType $bucket, int $horizon, array $warehouseIds = [], array $itemIds = [], ?string $userId = null): DrpRun
    {
        $horizon = max(1, min(60, $horizon));

        $run = DrpRun::create([
            'run_number' => 'DRP-'.Carbon::now()->format('YmdHis').'-'.Str::upper(Str::random(3)),
            'bucket_type' => $bucket->value,
            'horizon' => $horizon,
            'status' => RunStatus::Running->value,
            'started_at' => Carbon::now(),
            'parameters' => ['warehouse_ids' => $warehouseIds, 'stock_item_ids' => $itemIds],
            'triggered_by' => $userId,
        ]);

        try {
            return DB::transaction(fn (): DrpRun => $this->plan($run, $bucket, $horizon, $warehouseIds, $itemIds));
        } catch (Throwable $exception) {
            $run->update(['status' => RunStatus::Failed->value, 'finished_at' => Carbon::now(), 'summary' => ['error' => $exception->getMessage()]]);

            throw $exception;
        }
    }

    /**
     * @param  list<string>  $warehouseIds
     * @param  list<string>  $itemIds
     */
    private function plan(DrpRun $run, BucketType $bucket, int $horizon, array $warehouseIds, array $itemIds): DrpRun
    {
        $rules = ReplenishmentRule::query()->where('is_active', true)
            ->when($itemIds !== [], static fn ($query) => $query->whereIn('stock_item_id', $itemIds))
            ->get();

        $profiles = WarehouseProfile::query()->get()->keyBy('warehouse_id');
        $nodes = $this->nodes($rules, $profiles);
        $items = array_values(array_unique(array_column($nodes, 'item')));
        $warehouseSet = array_values(array_unique(array_column($nodes, 'warehouse')));

        $onHand = $this->positions->onHand($warehouseSet, $items);
        $receipts = $this->positions->scheduledReceipts($warehouseSet, $items);
        $issues = $this->positions->scheduledIssues($warehouseSet, $items);
        $rates = $this->demand->dailyRates($warehouseSet, $items);
        $orders = $this->demand->openSalesOrders($warehouseSet, $items);

        $length = $bucket->days();
        $today = Carbon::today();
        $buckets = [];

        for ($index = 0; $index < $horizon; $index++) {
            $buckets[] = ['index' => $index, 'start' => $today->copy()->addDays($index * $length)->toDateString(), 'end' => $today->copy()->addDays((($index + 1) * $length) - 1)->toDateString()];
        }

        $bucketOf = static function (string $date) use ($today, $length, $horizon): ?int {
            $days = (int) $today->diffInDays(Carbon::parse($date), false);
            $index = $days < 0 ? 0 : intdiv($days, $length);

            return $index >= $horizon ? null : $index;
        };

        $zero = array_fill(0, $horizon, '0');
        $series = static fn (): array => $zero;

        // Order of planning: the deepest nodes (stores) first, so their releases are known when the hub is planned.
        uasort($nodes, static fn (array $left, array $right): int => $right['depth'] <=> $left['depth']);

        $dependent = [];
        $lines = [];
        $alerts = [];
        $plannedReleaseTotal = '0';

        foreach ($nodes as $key => $node) {
            $gross = $series();
            $scheduled = $series();
            $sales = $series();
            $forecast = Quantity::mul($rates[$key] ?? '0', (string) $length);

            foreach ($orders as $order) {
                if ($order['warehouse_id'] === $node['warehouse'] && $order['stock_item_id'] === $node['item'] && ($index = $bucketOf($order['date'])) !== null) {
                    $sales[$index] = Quantity::add($sales[$index], $order['quantity']);
                }
            }

            foreach ($issues as $issue) {
                if ($issue['warehouse_id'] === $node['warehouse'] && $issue['stock_item_id'] === $node['item'] && ($index = $bucketOf($issue['date'])) !== null) {
                    $gross[$index] = Quantity::add($gross[$index], $issue['quantity']);
                }
            }

            foreach ($receipts as $receipt) {
                if ($receipt['warehouse_id'] === $node['warehouse'] && $receipt['stock_item_id'] === $node['item'] && ($index = $bucketOf($receipt['date'])) !== null) {
                    $scheduled[$index] = Quantity::add($scheduled[$index], $receipt['quantity']);
                }
            }

            for ($index = 0; $index < $horizon; $index++) {
                $gross[$index] = Quantity::add($gross[$index], Quantity::max($sales[$index], $forecast));
                $gross[$index] = Quantity::add($gross[$index], $dependent[$key][$index] ?? '0');
            }

            $balance = $onHand[$key]['on_hand'] ?? '0.0000';
            $opening = $balance;
            $rule = $node['rule'];
            $safety = $rule !== null ? Quantity::of((string) $rule->safety_stock) : '0.0000';
            $cells = [];
            $plannedReceipts = $series();
            $plannedReleases = $series();
            $maxBalance = null;
            $firstShort = null;
            $shortage = '0';
            $firstExcess = null;
            $lateRelease = null;
            $offset = (int) ceil($node['lead'] / $length);

            for ($index = 0; $index < $horizon; $index++) {
                $before = Quantity::sub(Quantity::add($balance, $scheduled[$index]), $gross[$index]);
                $net = Quantity::max('0', Quantity::sub($safety, $before));
                $planned = '0';

                if (Quantity::isPositive($net)) {
                    $planned = $this->lotSize($rule, $net, $before);
                    $release = $index - $offset;

                    if ($release < 0) {
                        $release = 0;
                        $lateRelease ??= $index;
                    }

                    $plannedReleases[$release] = Quantity::add($plannedReleases[$release], $planned);

                    if ($node['source'] !== null) {
                        $sourceKey = StockPositionService::key($node['source'], $node['item']);
                        $dependent[$sourceKey][$release] = Quantity::add($dependent[$sourceKey][$release] ?? '0', $planned);
                    }
                }

                $plannedReceipts[$index] = $planned;
                if ($firstShort === null && Quantity::isNegative($before)) {
                    $firstShort = $index;
                    $shortage = Quantity::abs($before);
                }

                $balance = Quantity::add($before, $planned);
                $maxBalance = $maxBalance === null ? $balance : Quantity::max($maxBalance, $balance);

                if ($rule !== null && Quantity::isPositive((string) $rule->max_stock) && Quantity::compare($balance, $rule->max_stock) > 0) {
                    $firstExcess ??= $index;
                }

                $cells[$index] = [
                    'index' => $index,
                    'gross_requirements' => Quantity::of($gross[$index]),
                    'scheduled_receipts' => Quantity::of($scheduled[$index]),
                    'projected_available' => Quantity::of($balance),
                    'net_requirements' => Quantity::of($net),
                    'planned_receipts' => Quantity::of($planned),
                    'planned_releases' => '0.0000',
                ];
            }

            foreach ($plannedReleases as $index => $released) {
                $cells[$index]['planned_releases'] = Quantity::of($released);
                $plannedReleaseTotal = Quantity::add($plannedReleaseTotal, $released);
            }

            $inScope = ($warehouseIds === [] || in_array($node['warehouse'], $warehouseIds, true)) && ($itemIds === [] || in_array($node['item'], $itemIds, true));

            if ($inScope) {
                $lines[] = DrpPlanLine::create([
                    'drp_run_id' => $run->id,
                    'warehouse_id' => $node['warehouse'],
                    'stock_item_id' => $node['item'],
                    'source_warehouse_id' => $node['source'],
                    'lead_time_days' => $node['lead'],
                    'opening_balance' => $opening,
                    'safety_stock' => $safety,
                    'cells' => array_values($cells),
                ]);

                if ($firstShort !== null) {
                    $alerts[] = $this->alert($run, AlertType::StockoutRisk, $firstShort === 0 ? 'critical' : 'warning', $node, $firstShort, ['shortage' => $shortage, 'bucket_start' => $buckets[$firstShort]['start'], 'late_release' => $lateRelease !== null]);
                } elseif ($lateRelease !== null) {
                    $alerts[] = $this->alert($run, AlertType::StockoutRisk, 'warning', $node, $lateRelease, ['bucket_start' => $buckets[$lateRelease]['start'], 'late_release' => true]);
                }

                if ($firstExcess !== null) {
                    $alerts[] = $this->alert($run, AlertType::ExcessStock, 'info', $node, $firstExcess, ['projected_available' => $cells[$firstExcess]['projected_available'], 'max_stock' => Quantity::of((string) $rule?->max_stock)]);
                }
            }
        }

        foreach ($receipts as $receipt) {
            if (in_array($receipt['status'], ['in_transit', 'partially_received'], true) && $receipt['date'] < $today->toDateString()
                && ($warehouseIds === [] || in_array($receipt['warehouse_id'], $warehouseIds, true))) {
                $alerts[] = DrpAlert::create([
                    'drp_run_id' => $run->id,
                    'alert_type' => AlertType::LateInbound->value,
                    'severity' => 'warning',
                    'warehouse_id' => $receipt['warehouse_id'],
                    'stock_item_id' => $receipt['stock_item_id'],
                    'transfer_order_id' => $receipt['transfer_order_id'],
                    'params' => ['expected_date' => $receipt['date'], 'quantity' => $receipt['quantity']],
                ]);
            }
        }

        $byType = static fn (AlertType $type): int => count(array_filter($alerts, static fn (DrpAlert $alert): bool => $alert->alert_type === $type));

        $run->update([
            'status' => RunStatus::Completed->value,
            'finished_at' => Carbon::now(),
            'summary' => [
                'rows' => count($lines),
                'alerts' => count($alerts),
                'stockout_alerts' => $byType(AlertType::StockoutRisk),
                'excess_alerts' => $byType(AlertType::ExcessStock),
                'late_inbound_alerts' => $byType(AlertType::LateInbound),
                'planned_release_quantity' => Quantity::of($plannedReleaseTotal),
                'buckets' => $buckets,
            ],
        ]);

        return $run->refresh();
    }

    /**
     * The planning nodes: every rule plus the hubs that supply them, with their lead time, source warehouse and depth in the network.
     *
     * @param  Collection<int, ReplenishmentRule>  $rules
     * @param  Collection<string, WarehouseProfile>  $profiles
     * @return array<string, array{warehouse: string, item: string, rule: ?ReplenishmentRule, source: ?string, lead: int, depth: int}>
     */
    private function nodes($rules, $profiles): array
    {
        $nodes = [];

        foreach ($rules as $rule) {
            $source = $rule->source_warehouse_id ?? $profiles->get($rule->warehouse_id)?->parent_warehouse_id;
            $lane = $source !== null ? $this->network->laneBetween((string) $source, (string) $rule->warehouse_id) : null;
            $nodes[StockPositionService::key((string) $rule->warehouse_id, (string) $rule->stock_item_id)] = [
                'warehouse' => (string) $rule->warehouse_id,
                'item' => (string) $rule->stock_item_id,
                'rule' => $rule,
                'source' => $source !== null ? (string) $source : null,
                'lead' => $rule->lead_time_days > 0 ? $rule->lead_time_days : ($lane?->totalLeadDays() ?? 0),
                'depth' => 0,
            ];
        }

        foreach ($nodes as $node) {
            if ($node['source'] === null) {
                continue;
            }

            $key = StockPositionService::key($node['source'], $node['item']);

            $nodes[$key] ??= [
                'warehouse' => $node['source'],
                'item' => $node['item'],
                'rule' => null,
                'source' => $profiles->get($node['source'])?->parent_warehouse_id !== null ? (string) $profiles->get($node['source'])->parent_warehouse_id : null,
                'lead' => 0,
                'depth' => 0,
            ];
        }

        foreach ($nodes as $key => $node) {
            $depth = 0;
            $cursor = $node['source'];
            $guard = 0;

            while ($cursor !== null && $guard++ < 20) {
                $depth++;
                $parent = $nodes[StockPositionService::key($cursor, $node['item'])] ?? null;
                $cursor = $parent['source'] ?? null;
            }

            // Nodes deeper in the chain (stores) have a higher depth and are planned first.
            $nodes[$key]['depth'] = $depth;
        }

        return $nodes;
    }

    /**
     * Lot size of a planned order receipt: the net requirement, lifted to the policy quantity, the minimum and the transfer multiple.
     */
    private function lotSize(?ReplenishmentRule $rule, string $net, string $projectedBefore): string
    {
        $quantity = $net;

        if ($rule !== null) {
            if ($rule->policy === ReplenishmentPolicy::ReorderPointFixedQty && Quantity::isPositive((string) $rule->fixed_quantity)) {
                $batches = max(1, (int) ceil((float) $net / (float) $rule->fixed_quantity));
                $quantity = Quantity::mul((string) $batches, (string) $rule->fixed_quantity);
            } elseif (Quantity::isPositive((string) $rule->max_stock)) {
                $quantity = Quantity::max($quantity, Quantity::sub((string) $rule->max_stock, $projectedBefore));
            }

            if (Quantity::isPositive((string) $rule->transfer_multiple)) {
                $steps = bcdiv($quantity, (string) $rule->transfer_multiple, 0);

                if (bccomp(bcmul($steps, (string) $rule->transfer_multiple, 4), Quantity::of($quantity), 4) < 0) {
                    $steps = bcadd($steps, '1', 0);
                }

                $quantity = Quantity::mul($steps, (string) $rule->transfer_multiple);
            }

            $quantity = Quantity::max($quantity, (string) $rule->min_transfer_qty);
        }

        return $quantity;
    }

    /**
     * @param  array{warehouse: string, item: string, rule: ?ReplenishmentRule, source: ?string, lead: int, depth: int}  $node
     * @param  array<string, mixed>  $params
     */
    private function alert(DrpRun $run, AlertType $type, string $severity, array $node, int $bucketIndex, array $params): DrpAlert
    {
        return DrpAlert::create([
            'drp_run_id' => $run->id,
            'alert_type' => $type->value,
            'severity' => $severity,
            'warehouse_id' => $node['warehouse'],
            'stock_item_id' => $node['item'],
            'bucket_index' => $bucketIndex,
            'params' => $params,
        ]);
    }
}
