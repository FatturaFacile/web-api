<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Services;

use Modules\TimeTravel\Domain\Enums\ValuationMethod;
use Modules\TimeTravel\Models\BiTemporalEvent;
use Modules\TimeTravel\Services\Projections\InventoryProjector;
use Modules\TimeTravel\Support\Amount;

/**
 * Sandboxed replay of the inventory stream under a valuation algorithm, entirely in memory: it reads events and writes nothing.
 * Receipts add a cost layer at the cost they were booked; issues consume layers (FIFO oldest first, LIFO newest first) or the running
 * weighted average (WAC); a pure value event (revaluation) is spread over the quantity on hand. The events must be fed in valid-time order.
 */
final class InventoryCostReplayer
{
    /** @var array<string, array{item_id: string, warehouse_id: string, layers: list<array{qty: string, cost: string}>, quantity: string, value: string, official: string}> */
    private array $books = [];

    public function __construct(private readonly ValuationMethod $method, private readonly InventoryProjector $projector = new InventoryProjector) {}

    public function feed(BiTemporalEvent $event): void
    {
        foreach ($this->projector->legs($event) as $leg) {
            $key = $leg['item_id'].'|'.$leg['warehouse_id'];
            $book = $this->books[$key] ?? ['item_id' => $leg['item_id'], 'warehouse_id' => $leg['warehouse_id'], 'layers' => [], 'quantity' => '0', 'value' => '0', 'official' => '0'];
            $book['official'] = Amount::add($book['official'], $leg['value_delta']);

            if (Amount::cmp($leg['quantity_delta'], 0) > 0) {
                $cost = Amount::div($leg['value_delta'], $leg['quantity_delta']);
                $book = $this->receive($book, $leg['quantity_delta'], $cost);
            } elseif (Amount::cmp($leg['quantity_delta'], 0) < 0) {
                $fallback = Amount::cmp($leg['unit_cost'], 0) > 0 ? $leg['unit_cost'] : Amount::div(Amount::neg($leg['value_delta']), Amount::neg($leg['quantity_delta']));
                $book = $this->issue($book, Amount::neg($leg['quantity_delta']), $fallback);
            } else {
                $book = $this->revalue($book, $leg['value_delta']);
            }

            $this->books[$key] = $book;
        }
    }

    /**
     * @return list<array{item_id: string, warehouse_id: string, quantity: string, official_value: string, simulated_value: string, variance: string, variance_pct: string}>
     */
    public function results(): array
    {
        $items = [];

        foreach ($this->books as $book) {
            if (Amount::isZero($book['quantity']) && Amount::isZero($book['value']) && Amount::isZero($book['official'])) {
                continue;
            }

            $variance = Amount::sub($book['value'], $book['official']);
            $items[] = [
                'item_id' => $book['item_id'],
                'warehouse_id' => $book['warehouse_id'],
                'quantity' => Amount::quantity($book['quantity']),
                'official_value' => Amount::money($book['official']),
                'simulated_value' => Amount::money($book['value']),
                'variance' => Amount::money($variance),
                'variance_pct' => Amount::round(Amount::isZero($book['official']) ? 0 : Amount::mul(Amount::div($variance, $book['official']), 100), 2),
            ];
        }

        usort($items, static fn (array $a, array $b): int => [$a['item_id'], $a['warehouse_id']] <=> [$b['item_id'], $b['warehouse_id']]);

        return $items;
    }

    /**
     * @param  array{item_id: string, warehouse_id: string, layers: list<array{qty: string, cost: string}>, quantity: string, value: string, official: string}  $book
     * @return array{item_id: string, warehouse_id: string, layers: list<array{qty: string, cost: string}>, quantity: string, value: string, official: string}
     */
    private function receive(array $book, string $quantity, string $cost): array
    {
        $book['quantity'] = Amount::add($book['quantity'], $quantity);
        $book['value'] = Amount::add($book['value'], Amount::mul($quantity, $cost));

        if ($this->method !== ValuationMethod::Wac) {
            $book['layers'][] = ['qty' => $quantity, 'cost' => $cost];
        }

        return $book;
    }

    /**
     * @param  array{item_id: string, warehouse_id: string, layers: list<array{qty: string, cost: string}>, quantity: string, value: string, official: string}  $book
     * @return array{item_id: string, warehouse_id: string, layers: list<array{qty: string, cost: string}>, quantity: string, value: string, official: string}
     */
    private function issue(array $book, string $quantity, string $fallbackCost): array
    {
        if ($this->method === ValuationMethod::Wac) {
            $average = Amount::cmp($book['quantity'], 0) > 0 ? Amount::div($book['value'], $book['quantity']) : $fallbackCost;
            $book['quantity'] = Amount::sub($book['quantity'], $quantity);
            $book['value'] = Amount::sub($book['value'], Amount::mul($quantity, $average));

            // The last unit out leaves no rounding dust behind.
            if (Amount::isZero($book['quantity'])) {
                $book['value'] = Amount::of(0);
            }

            return $book;
        }

        $remaining = $quantity;
        $layers = $book['layers'];
        $consumed = '0';

        while (Amount::cmp($remaining, 0) > 0 && $layers !== []) {
            $index = $this->method === ValuationMethod::Fifo ? 0 : array_key_last($layers);
            $layer = $layers[$index];
            $take = Amount::min($remaining, $layer['qty']);
            $consumed = Amount::add($consumed, Amount::mul($take, $layer['cost']));
            $remaining = Amount::sub($remaining, $take);
            $left = Amount::sub($layer['qty'], $take);

            if (Amount::isZero($left)) {
                unset($layers[$index]);
                $layers = array_values($layers);
            } else {
                $layers[$index]['qty'] = $left;
            }
        }

        // An issue beyond the layers (negative stock) is costed at the cost booked on the movement.
        if (Amount::cmp($remaining, 0) > 0) {
            $consumed = Amount::add($consumed, Amount::mul($remaining, $fallbackCost));
        }

        $book['layers'] = $layers;
        $book['quantity'] = Amount::sub($book['quantity'], $quantity);
        $book['value'] = Amount::sub($book['value'], $consumed);

        return $book;
    }

    /**
     * @param  array{item_id: string, warehouse_id: string, layers: list<array{qty: string, cost: string}>, quantity: string, value: string, official: string}  $book
     * @return array{item_id: string, warehouse_id: string, layers: list<array{qty: string, cost: string}>, quantity: string, value: string, official: string}
     */
    private function revalue(array $book, string $delta): array
    {
        $book['value'] = Amount::add($book['value'], $delta);

        if ($this->method !== ValuationMethod::Wac && Amount::cmp($book['quantity'], 0) > 0) {
            $perUnit = Amount::div($delta, $book['quantity']);

            foreach ($book['layers'] as $index => $layer) {
                $book['layers'][$index]['cost'] = Amount::add($layer['cost'], $perUnit);
            }
        }

        return $book;
    }
}
