<?php

declare(strict_types=1);

namespace Modules\Distribution\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Distribution\Domain\Enums\ProposalStatus;
use Modules\Distribution\Domain\Enums\ProposalType;
use Modules\Distribution\Domain\Enums\ReplenishmentPolicy;
use Modules\Distribution\Events\ReplenishmentShortfallDetected;
use Modules\Distribution\Models\ReplenishmentProposal;
use Modules\Distribution\Models\ReplenishmentRule;
use Modules\Distribution\Models\WarehouseProfile;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Support\Quantity;

/**
 * Replenishment and stock balancing (DRP engine).
 *
 * For every active rule it projects the position of the warehouse (free stock + stock on the way - demand over the lead time),
 * applies the policy (min-max, reorder point with fixed quantity, periodic review, demand-driven DRP) and, when the position is
 * short, first looks for excess stock at peer warehouses connected by a lane (lateral balancing, cheapest lead time first) and only
 * then asks the supplying hub. When the hub cannot cover the need the shortfall is flagged (and announced to procurement / MRP
 * through ReplenishmentShortfallDetected) instead of being lost.
 */
final class ReplenishmentEngine
{
    public function __construct(
        private readonly StockPositionService $positions,
        private readonly DemandService $demand,
        private readonly NetworkService $network,
    ) {}

    /**
     * @param  list<string>  $warehouseIds  limit the proposals to these destination warehouses (empty = all)
     * @param  list<string>  $itemIds  limit the proposals to these items (empty = all)
     * @return array{created: int, balancing: int, shortfalls: int, proposals: list<ReplenishmentProposal>}
     */
    public function generate(array $warehouseIds = [], array $itemIds = [], ?string $drpRunId = null): array
    {
        $rules = ReplenishmentRule::query()->where('is_active', true)
            ->when($itemIds !== [], static fn ($query) => $query->whereIn('stock_item_id', $itemIds))
            ->orderBy('priority')
            ->get();

        $scoped = $rules->filter(static fn (ReplenishmentRule $rule): bool => $warehouseIds === [] || in_array((string) $rule->warehouse_id, $warehouseIds, true));

        if ($scoped->isEmpty()) {
            return ['created' => 0, 'balancing' => 0, 'shortfalls' => 0, 'proposals' => []];
        }

        $items = $rules->pluck('stock_item_id')->unique()->values()->all();
        $warehouseSet = $rules->pluck('warehouse_id')->merge($rules->pluck('source_warehouse_id'))->filter()->unique()->values()->all();
        $onHand = $this->positions->onHand($warehouseSet, $items);
        $inbound = $this->positions->inTransitInbound($warehouseSet, $items);
        $rates = $this->demand->dailyRates($warehouseSet, $items);
        $orders = $this->demand->openSalesOrders($warehouseSet, $items);
        $warehouses = Warehouse::query()->whereIn('id', $warehouseSet)->get()->keyBy('id');
        $roles = $this->network->roles($warehouses);
        $profiles = WarehouseProfile::query()->whereIn('warehouse_id', $warehouseSet)->get()->keyBy('warehouse_id');
        $rulesByKey = $rules->keyBy(static fn (ReplenishmentRule $rule): string => StockPositionService::key((string) $rule->warehouse_id, (string) $rule->stock_item_id));
        $today = Carbon::today();

        $positionOf = function (ReplenishmentRule $rule, int $horizonDays) use ($onHand, $inbound, $rates, $orders, $today): array {
            $key = StockPositionService::key((string) $rule->warehouse_id, (string) $rule->stock_item_id);
            $stock = $onHand[$key] ?? ['on_hand' => '0.0000', 'reserved' => '0.0000', 'available' => '0.0000'];
            $rate = $rates[$key] ?? '0.0000';
            $forecast = Quantity::mul($rate, (string) $horizonDays);
            $until = $today->copy()->addDays($horizonDays)->toDateString();
            $committed = '0';

            foreach ($orders as $order) {
                if ($order['warehouse_id'] === (string) $rule->warehouse_id && $order['stock_item_id'] === (string) $rule->stock_item_id && $order['date'] <= $until) {
                    $committed = Quantity::add($committed, $order['quantity']);
                }
            }

            $projected = Quantity::max($forecast, $committed);
            $incoming = $inbound[$key] ?? '0.0000';

            return $stock + [
                'in_transit_inbound' => $incoming,
                'daily_rate' => $rate,
                'projected_demand' => $projected,
                'net_position' => Quantity::sub(Quantity::add($stock['available'], $incoming), $projected),
            ];
        };

        // Evaluate every rule once: the shortage to cover and the excess that can be lent to a peer.
        $needs = [];
        $excess = [];

        foreach ($rules as $rule) {
            $horizon = $rule->lead_time_days + ($rule->policy === ReplenishmentPolicy::PeriodicReview ? (int) $rule->review_cycle_days : 0);
            $position = $positionOf($rule, max(1, $horizon));
            $key = StockPositionService::key((string) $rule->warehouse_id, (string) $rule->stock_item_id);
            $evaluation = $this->evaluate($rule, $position);

            if ($evaluation !== null) {
                $needs[$key] = ['rule' => $rule, 'position' => $position, 'evaluation' => $evaluation];

                continue;
            }

            $upper = $this->upperLevel($rule, $position);
            $role = $roles[(string) $rule->warehouse_id] ?? null;

            if ($upper !== null && $role?->isPlannable() === true && $profiles->get($rule->warehouse_id)?->is_blocked !== true) {
                $spare = Quantity::sub(Quantity::add($position['available'], $position['in_transit_inbound']), $upper);
                $spare = Quantity::min($spare, $position['available']);

                if (Quantity::isPositive($spare)) {
                    $excess[$key] = $spare;
                }
            }
        }

        $created = [];
        $balancing = 0;
        $shortfalls = 0;

        DB::transaction(function () use ($scoped, $needs, &$excess, $rulesByKey, $profiles, $onHand, $drpRunId, $today, &$created, &$balancing, &$shortfalls): void {
            // A new run replaces the proposals nobody decided on yet.
            foreach ($scoped as $rule) {
                ReplenishmentProposal::query()
                    ->where('warehouse_id', $rule->warehouse_id)
                    ->where('stock_item_id', $rule->stock_item_id)
                    ->whereIn('status', [ProposalStatus::Pending->value, ProposalStatus::Shortfall->value])
                    ->delete();
            }

            $hubStock = [];

            foreach ($needs as $key => $need) {
                /** @var ReplenishmentRule $rule */
                $rule = $need['rule'];

                if (! $scoped->contains(static fn (ReplenishmentRule $candidate): bool => $candidate->id === $rule->id)) {
                    continue;
                }

                $position = $need['position'];
                $evaluation = $need['evaluation'];
                $remaining = $this->roundUp($rule, $evaluation['needed']);
                $stockout = $this->stockoutDate($position, $today);
                $steps = $evaluation['steps'];
                $steps[] = ['label' => 'rounded_quantity', 'value' => $remaining];

                // 1. Lateral balancing: lend the excess of peers that a lane connects to the shortage.
                $peers = [];

                foreach ($excess as $peerKey => $spare) {
                    [$peerWarehouse, $peerItem] = explode('|', $peerKey);

                    if ($peerItem !== (string) $rule->stock_item_id || $peerWarehouse === (string) $rule->warehouse_id) {
                        continue;
                    }

                    $lane = $this->network->laneBetween($peerWarehouse, (string) $rule->warehouse_id);

                    if ($lane !== null) {
                        $peers[] = ['warehouse' => $peerWarehouse, 'key' => $peerKey, 'spare' => $spare, 'lane' => $lane];
                    }
                }

                usort($peers, static fn (array $left, array $right): int => [$left['lane']->totalLeadDays(), -1 * (float) $left['spare']] <=> [$right['lane']->totalLeadDays(), -1 * (float) $right['spare']]);

                foreach ($peers as $peer) {
                    if (! Quantity::isPositive($remaining)) {
                        break;
                    }

                    $take = $this->roundDown($rule, Quantity::min($remaining, $excess[$peer['key']]));

                    if (! Quantity::isPositive($take) || Quantity::compare($take, $rule->min_transfer_qty) < 0) {
                        continue;
                    }

                    $excess[$peer['key']] = Quantity::sub($excess[$peer['key']], $take);
                    $remaining = Quantity::sub($remaining, $take);
                    $balancing++;

                    $created[] = ReplenishmentProposal::create([
                        'proposal_type' => ProposalType::Balancing->value,
                        'status' => ProposalStatus::Pending->value,
                        'warehouse_id' => $rule->warehouse_id,
                        'stock_item_id' => $rule->stock_item_id,
                        'source_warehouse_id' => $peer['warehouse'],
                        'rule_id' => $rule->id,
                        'suggested_quantity' => $take,
                        'original_quantity' => $take,
                        'shortfall_quantity' => '0',
                        'source_available_quantity' => $onHand[$peer['key']]['available'] ?? '0',
                        'projected_stockout_date' => $stockout,
                        'calculation' => $this->calculation($rule, $position, $evaluation, [...$steps, ['label' => 'balanced_from_peer', 'value' => $take]], 'balancing', ['lane_lead_days' => $peer['lane']->totalLeadDays(), 'peer_spare' => $peer['spare']]),
                        'drp_run_id' => $drpRunId,
                        'generated_at' => Carbon::now(),
                    ]);
                }

                if (! Quantity::isPositive($remaining)) {
                    continue;
                }

                // 2. The supplying hub (the rule's source, or the parent warehouse of the network).
                $sourceId = $rule->source_warehouse_id ?? $profiles->get($rule->warehouse_id)?->parent_warehouse_id;

                if ($sourceId === null) {
                    $shortfalls++;
                    $created[] = $this->shortfall($rule, $position, $evaluation, $steps, $remaining, $stockout, 'no_source', $drpRunId);

                    continue;
                }

                $hubKey = StockPositionService::key((string) $sourceId, (string) $rule->stock_item_id);
                $hubFree = $hubStock[$hubKey] ?? $this->hubFree($onHand[$hubKey]['available'] ?? '0', $rulesByKey->get($hubKey));
                $allotted = $this->roundDown($rule, Quantity::min($remaining, $hubFree));
                $hubStock[$hubKey] = Quantity::sub($hubFree, $allotted);
                $missing = Quantity::sub($remaining, $allotted);
                $steps[] = ['label' => 'hub_available', 'value' => $hubFree];

                if (! Quantity::isPositive($allotted) || Quantity::compare($allotted, $rule->min_transfer_qty) < 0) {
                    $shortfalls++;
                    $created[] = $this->shortfall($rule, $position, $evaluation, $steps, $remaining, $stockout, 'hub_shortage', $drpRunId, (string) $sourceId, $hubFree);

                    continue;
                }

                if (Quantity::isPositive($missing)) {
                    $shortfalls++;
                    event(new ReplenishmentShortfallDetected((string) $rule->tenant_id, (string) $rule->warehouse_id, (string) $rule->stock_item_id, $missing));
                    $steps[] = ['label' => 'shortfall', 'value' => $missing];
                }

                $created[] = ReplenishmentProposal::create([
                    'proposal_type' => ProposalType::Replenishment->value,
                    'status' => ProposalStatus::Pending->value,
                    'warehouse_id' => $rule->warehouse_id,
                    'stock_item_id' => $rule->stock_item_id,
                    'source_warehouse_id' => $sourceId,
                    'rule_id' => $rule->id,
                    'suggested_quantity' => $allotted,
                    'original_quantity' => $allotted,
                    'shortfall_quantity' => $missing,
                    'source_available_quantity' => $hubFree,
                    'projected_stockout_date' => $stockout,
                    'calculation' => $this->calculation($rule, $position, $evaluation, $steps, 'replenishment'),
                    'drp_run_id' => $drpRunId,
                    'generated_at' => Carbon::now(),
                ]);
            }
        });

        return [
            'created' => count($created) - $balancing,
            'balancing' => $balancing,
            'shortfalls' => $shortfalls,
            'proposals' => $created,
        ];
    }

    /**
     * What the policy asks for, or null when the position is healthy.
     *
     * @param  array<string, string>  $position
     * @return array{needed: string, reason: string, steps: list<array{label: string, value: string}>}|null
     */
    private function evaluate(ReplenishmentRule $rule, array $position): ?array
    {
        $net = $position['net_position'];
        $steps = [
            ['label' => 'on_hand', 'value' => $position['available']],
            ['label' => 'in_transit_inbound', 'value' => $position['in_transit_inbound']],
            ['label' => 'projected_demand', 'value' => $position['projected_demand']],
            ['label' => 'net_position', 'value' => $net],
        ];

        switch ($rule->policy) {
            case ReplenishmentPolicy::MinMax:
                if (Quantity::compare($net, $rule->reorder_point) > 0) {
                    return null;
                }

                $steps[] = ['label' => 'reorder_point', 'value' => (string) $rule->reorder_point];
                $steps[] = ['label' => 'max_stock', 'value' => (string) $rule->max_stock];
                $target = Quantity::max($rule->max_stock, Quantity::add($rule->reorder_point, '0'));
                $needed = Quantity::sub($target, $net);

                return ['needed' => $needed, 'reason' => 'below_reorder_point', 'steps' => [...$steps, ['label' => 'needed_quantity', 'value' => $needed]]];

            case ReplenishmentPolicy::ReorderPointFixedQty:
                if (Quantity::compare($net, $rule->reorder_point) > 0 || ! Quantity::isPositive($rule->fixed_quantity)) {
                    return null;
                }

                $steps[] = ['label' => 'reorder_point', 'value' => (string) $rule->reorder_point];
                $gap = Quantity::sub($rule->reorder_point, $net);
                $batches = max(1, (int) ceil((float) $gap / (float) $rule->fixed_quantity));
                $needed = Quantity::mul((string) $batches, $rule->fixed_quantity);

                return ['needed' => $needed, 'reason' => 'below_reorder_point', 'steps' => [...$steps, ['label' => 'needed_quantity', 'value' => $needed]]];

            case ReplenishmentPolicy::PeriodicReview:
                $target = $this->periodicTarget($rule, $position);

                if (Quantity::compare($net, $target) >= 0) {
                    return null;
                }

                $needed = Quantity::sub($target, $net);

                return ['needed' => $needed, 'reason' => 'below_target_level', 'steps' => [...$steps, ['label' => 'target_level', 'value' => $target], ['label' => 'needed_quantity', 'value' => $needed]]];

            case ReplenishmentPolicy::DemandDrivenDrp:
                if (Quantity::compare($net, $rule->safety_stock) >= 0) {
                    return null;
                }

                $needed = Quantity::sub($rule->safety_stock, $net);

                return ['needed' => $needed, 'reason' => 'below_safety_stock', 'steps' => [...$steps, ['label' => 'safety_stock', 'value' => (string) $rule->safety_stock], ['label' => 'needed_quantity', 'value' => $needed]]];
        }
    }

    /**
     * @param  array<string, string>  $position
     */
    private function periodicTarget(ReplenishmentRule $rule, array $position): string
    {
        $cover = (int) $rule->review_cycle_days + $rule->lead_time_days;
        $target = Quantity::add($rule->safety_stock, Quantity::mul($position['daily_rate'], (string) $cover));

        if ($rule->target_days_of_supply !== null) {
            $target = Quantity::max($target, Quantity::add($rule->safety_stock, Quantity::mul($position['daily_rate'], (string) $rule->target_days_of_supply)));
        }

        return Quantity::max($target, $rule->max_stock);
    }

    /**
     * The level above which stock is excess and can be lent to a peer (null: the rule defines none).
     *
     * @param  array<string, string>  $position
     */
    private function upperLevel(ReplenishmentRule $rule, array $position): ?string
    {
        if ($rule->policy === ReplenishmentPolicy::PeriodicReview) {
            return $this->periodicTarget($rule, $position);
        }

        if (Quantity::isPositive($rule->max_stock)) {
            return Quantity::add((string) $rule->max_stock, '0');
        }

        return null;
    }

    private function hubFree(string $available, ?ReplenishmentRule $hubRule): string
    {
        return $hubRule === null ? $available : Quantity::max('0', Quantity::sub($available, $hubRule->safety_stock));
    }

    /**
     * @param  array<string, string>  $position
     */
    private function stockoutDate(array $position, Carbon $today): ?string
    {
        if (! Quantity::isPositive($position['daily_rate'])) {
            return null;
        }

        $cover = Quantity::div(Quantity::add($position['available'], $position['in_transit_inbound']), $position['daily_rate']) ?? '0';

        return $today->copy()->addDays((int) floor((float) $cover))->toDateString();
    }

    /** Rounds up to the transfer multiple and to the minimum transfer quantity. */
    private function roundUp(ReplenishmentRule $rule, string $quantity): string
    {
        $multiple = (string) $rule->transfer_multiple;

        if (Quantity::isPositive($multiple)) {
            $steps = bcdiv($quantity, $multiple, 0);

            if (bccomp(bcmul($steps, $multiple, 4), Quantity::of($quantity), 4) < 0) {
                $steps = bcadd($steps, '1', 0);
            }

            $quantity = Quantity::mul($steps, $multiple);
        }

        return Quantity::max($quantity, $rule->min_transfer_qty);
    }

    private function roundDown(ReplenishmentRule $rule, string $quantity): string
    {
        $multiple = (string) $rule->transfer_multiple;

        if (! Quantity::isPositive($multiple)) {
            return Quantity::of($quantity);
        }

        return Quantity::mul(bcdiv($quantity, $multiple, 0), $multiple);
    }

    /**
     * @param  array<string, string>  $position
     * @param  array{needed: string, reason: string, steps: list<array{label: string, value: string}>}  $evaluation
     * @param  list<array{label: string, value: string}>  $steps
     */
    private function shortfall(ReplenishmentRule $rule, array $position, array $evaluation, array $steps, string $missing, ?string $stockout, string $reason, ?string $drpRunId, ?string $sourceId = null, string $hubFree = '0'): ReplenishmentProposal
    {
        event(new ReplenishmentShortfallDetected((string) $rule->tenant_id, (string) $rule->warehouse_id, (string) $rule->stock_item_id, $missing));

        return ReplenishmentProposal::create([
            'proposal_type' => ProposalType::Replenishment->value,
            'status' => ProposalStatus::Shortfall->value,
            'warehouse_id' => $rule->warehouse_id,
            'stock_item_id' => $rule->stock_item_id,
            'source_warehouse_id' => $sourceId,
            'rule_id' => $rule->id,
            'suggested_quantity' => '0',
            'original_quantity' => '0',
            'shortfall_quantity' => $missing,
            'source_available_quantity' => $hubFree,
            'projected_stockout_date' => $stockout,
            'calculation' => $this->calculation($rule, $position, $evaluation, [...$steps, ['label' => 'shortfall', 'value' => $missing]], 'replenishment', ['shortfall_reason' => $reason]),
            'drp_run_id' => $drpRunId,
            'generated_at' => Carbon::now(),
        ]);
    }

    /**
     * @param  array<string, string>  $position
     * @param  array{needed: string, reason: string, steps: list<array{label: string, value: string}>}  $evaluation
     * @param  list<array{label: string, value: string}>  $steps
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function calculation(ReplenishmentRule $rule, array $position, array $evaluation, array $steps, string $kind, array $extra = []): array
    {
        return [
            'policy' => $rule->policy->value,
            'kind' => $kind,
            'trigger_reason' => $evaluation['reason'],
            'on_hand' => $position['on_hand'],
            'reserved' => $position['reserved'],
            'available' => $position['available'],
            'in_transit_inbound' => $position['in_transit_inbound'],
            'safety_stock' => Quantity::of((string) $rule->safety_stock),
            'reorder_point' => Quantity::of((string) $rule->reorder_point),
            'max_stock' => Quantity::of((string) $rule->max_stock),
            'daily_rate' => $position['daily_rate'],
            'projected_demand' => $position['projected_demand'],
            'net_position' => $position['net_position'],
            'steps' => $steps,
        ] + $extra;
    }
}
