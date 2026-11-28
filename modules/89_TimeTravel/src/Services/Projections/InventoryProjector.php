<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Services\Projections;

use Carbon\CarbonImmutable;
use Modules\TimeTravel\Domain\Enums\AggregateType;
use Modules\TimeTravel\Domain\Enums\QueryDomain;
use Modules\TimeTravel\Models\BiTemporalEvent;
use Modules\TimeTravel\Support\Amount;

/**
 * Warehouse inventory: quantity and value per item, warehouse and lot, as the sum of the signed legs of every movement and cost update.
 * Event payload: item_id, lot_id, unit_cost, movement_id and legs [{warehouse_id, item_id?, quantity_delta, value_delta}]. Events:
 * InventoryLotMoved (receipts, issues, transfers between warehouses) and StandardCostUpdated (revaluations: value only).
 */
final class InventoryProjector implements StateProjector
{
    public function aggregateType(): AggregateType
    {
        return AggregateType::InventoryWms;
    }

    public function checkpointDomain(): QueryDomain
    {
        return QueryDomain::Inventory;
    }

    public function usesCheckpoint(QueryDomain $domain): bool
    {
        return true;
    }

    public function initialState(QueryDomain $domain, array $filters): array
    {
        return ['positions' => []];
    }

    public function apply(array $state, BiTemporalEvent $event): array
    {
        foreach ($this->legs($event) as $leg) {
            $key = $this->key($leg['item_id'], $leg['warehouse_id'], $leg['lot_id']);
            $position = $state['positions'][$key] ?? ['item_id' => $leg['item_id'], 'warehouse_id' => $leg['warehouse_id'], 'lot_id' => $leg['lot_id'], 'quantity' => '0', 'value' => '0'];
            $position['quantity'] = Amount::add($position['quantity'], $leg['quantity_delta']);
            $position['value'] = Amount::add($position['value'], $leg['value_delta']);
            $state['positions'][$key] = $position;
        }

        return $state;
    }

    public function render(QueryDomain $domain, array $state, array $filters, CarbonImmutable $validAt, string $tenantId): array
    {
        $rows = [];
        $quantity = '0';
        $value = '0';

        /** @var array<string, array<string, mixed>> $positions */
        $positions = $state['positions'];

        foreach ($positions as $key => $position) {
            if (! $this->passes($position['item_id'], $position['warehouse_id'], $filters)) {
                continue;
            }

            if (Amount::isZero($position['quantity']) && Amount::isZero($position['value'])) {
                continue;
            }

            $rows[] = [
                'key' => (string) $key,
                'item_id' => $position['item_id'],
                'warehouse_id' => $position['warehouse_id'],
                'lot_id' => $position['lot_id'] !== '' ? $position['lot_id'] : null,
                'quantity' => Amount::quantity($position['quantity']),
                'value' => Amount::money($position['value']),
                'unit_cost' => Amount::round(Amount::div($position['value'], $position['quantity']), 4),
            ];
            $quantity = Amount::add($quantity, $position['quantity']);
            $value = Amount::add($value, $position['value']);
        }

        usort($rows, static fn (array $a, array $b): int => [$a['item_id'], $a['warehouse_id'], (string) $a['lot_id']] <=> [$b['item_id'], $b['warehouse_id'], (string) $b['lot_id']]);

        return ['rows' => $rows, 'totals' => ['quantity' => Amount::quantity($quantity), 'value' => Amount::money($value)]];
    }

    public function comparable(QueryDomain $domain, array $rows): array
    {
        $comparable = [];

        foreach ($rows as $row) {
            $comparable[(string) $row['key']] = [
                'label' => $row['item_id'].' @ '.$row['warehouse_id'].($row['lot_id'] !== null ? ' / '.$row['lot_id'] : ''),
                'value' => (string) $row['value'],
                'weight' => (string) $row['value'],
            ];
        }

        return $comparable;
    }

    public function matches(QueryDomain $domain, BiTemporalEvent $event, array $filters): bool
    {
        return array_any($this->legs($event), fn (array $leg): bool => $this->passes($leg['item_id'], $leg['warehouse_id'], $filters));
    }

    public function affectedKeys(QueryDomain $domain, BiTemporalEvent $event, array $filters): array
    {
        $keys = [];

        foreach ($this->legs($event) as $leg) {
            if ($this->passes($leg['item_id'], $leg['warehouse_id'], $filters)) {
                $keys[$this->key($leg['item_id'], $leg['warehouse_id'], $leg['lot_id'])] = true;
            }
        }

        return array_map('strval', array_keys($keys));
    }

    public function amountDelta(QueryDomain $domain, BiTemporalEvent $event, array $filters): string
    {
        $delta = '0';

        foreach ($this->legs($event) as $leg) {
            if ($this->passes($leg['item_id'], $leg['warehouse_id'], $filters)) {
                $delta = Amount::add($delta, $leg['value_delta']);
            }
        }

        return Amount::money($delta);
    }

    public function sourceDocument(BiTemporalEvent $event): ?string
    {
        $movement = (string) ($event->payload['movement_id'] ?? $event->payload['revaluation_id'] ?? '');

        return $movement !== '' ? $movement : null;
    }

    /**
     * The normalized legs of an event (the replay of the valuation reads the same).
     *
     * @return list<array{item_id: string, warehouse_id: string, lot_id: string, quantity_delta: string, value_delta: string, unit_cost: string}>
     */
    public function legs(BiTemporalEvent $event): array
    {
        $payload = $event->payload;
        $legs = [];

        foreach (is_array($payload['legs'] ?? null) ? $payload['legs'] : [] as $leg) {
            if (! is_array($leg)) {
                continue;
            }

            $legs[] = [
                'item_id' => (string) ($leg['item_id'] ?? $payload['item_id'] ?? ''),
                'warehouse_id' => (string) ($leg['warehouse_id'] ?? ''),
                'lot_id' => (string) ($leg['lot_id'] ?? $payload['lot_id'] ?? ''),
                'quantity_delta' => Amount::of($leg['quantity_delta'] ?? 0),
                'value_delta' => Amount::of($leg['value_delta'] ?? 0),
                'unit_cost' => Amount::of($payload['unit_cost'] ?? 0),
            ];
        }

        return $legs;
    }

    private function key(string $itemId, string $warehouseId, string $lotId): string
    {
        return $itemId.'|'.$warehouseId.'|'.$lotId;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function passes(string $itemId, string $warehouseId, array $filters): bool
    {
        $item = (string) ($filters['item_id'] ?? '');
        $warehouse = (string) ($filters['warehouse_id'] ?? '');

        return ($item === '' || $item === $itemId) && ($warehouse === '' || $warehouse === $warehouseId);
    }
}
