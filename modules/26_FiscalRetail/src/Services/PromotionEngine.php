<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Modules\FiscalRetail\Models\RetailPromotion;

/**
 * Computes the discounts of a ticket from the promotions of the company. Amounts are net of VAT decimal strings.
 *
 * Promotions are tried by priority (highest first, then name). A promotion applies when it is active, inside its
 * period, weekdays and time window, and the subtotal of the lines it targets reaches its minimum. A promotion without
 * product targets the whole ticket, one with a product only the lines of that product. A non-stackable promotion only
 * takes lines no other promotion touched and locks them afterwards; a stackable one works on what is left of a line.
 * Buy X get Y gives away the cheapest whole units of the product, per complete group of X + Y units.
 */
final class PromotionEngine
{
    /**
     * @param  list<array{product_id: string|null, quantity: numeric-string, unit_price: numeric-string, tax_rate: numeric-string}>  $lines
     * @param  Collection<int, RetailPromotion>  $promotions
     * @return array{applied: list<array{promotion_id: string, code: string, name: string, discount_net: string, by_rate: array<string, string>}>, discount_total: string}
     */
    public function evaluate(array $lines, CarbonInterface $at, Collection $promotions): array
    {
        $state = [];

        foreach ($lines as $index => $line) {
            $state[$index] = [
                'product_id' => $line['product_id'],
                'quantity' => (string) $line['quantity'],
                'unit_price' => (string) $line['unit_price'],
                'rate' => bcadd((string) $line['tax_rate'], '0', 2),
                'net' => bcround(bcmul((string) $line['quantity'], (string) $line['unit_price'], 6), 2),
                'discount' => '0.00',
                'locked' => false,
            ];
        }

        $applied = [];
        $total = '0.00';

        foreach ($promotions->filter(fn (RetailPromotion $promotion): bool => $this->isActiveAt($promotion, $at))->sort(
            static fn (RetailPromotion $a, RetailPromotion $b): int => [$b->priority, $a->name] <=> [$a->priority, $b->name],
        ) as $promotion) {
            $eligible = $this->eligibleLines($promotion, $state);
            $subtotal = $this->remaining($eligible, $state);

            if ($eligible === [] || bccomp($subtotal, '0', 2) <= 0) {
                continue;
            }

            if ($promotion->min_subtotal !== null && bccomp($subtotal, (string) $promotion->min_subtotal, 2) < 0) {
                continue;
            }

            $perLine = $promotion->type === 'buy_x_get_y'
                ? $this->freeUnits($promotion, $eligible, $state)
                : $this->spread($this->amountOff($promotion, $subtotal), $eligible, $state);

            $discount = array_reduce($perLine, static fn (string $sum, string $amount): string => bcadd($sum, $amount, 2), '0.00');

            if (bccomp($discount, '0', 2) <= 0) {
                continue;
            }

            $byRate = [];

            foreach ($perLine as $index => $amount) {
                $state[$index]['discount'] = bcadd($state[$index]['discount'], $amount, 2);
                $byRate[$state[$index]['rate']] = bcadd($byRate[$state[$index]['rate']] ?? '0.00', $amount, 2);
            }

            if (! $promotion->is_stackable) {
                foreach (array_keys($perLine) as $index) {
                    $state[$index]['locked'] = true;
                }
            }

            $total = bcadd($total, $discount, 2);
            $applied[] = [
                'promotion_id' => (string) $promotion->id,
                'code' => $promotion->code,
                'name' => $promotion->name,
                'discount_net' => $discount,
                'by_rate' => $byRate,
            ];
        }

        return ['applied' => $applied, 'discount_total' => $total];
    }

    public function isActiveAt(RetailPromotion $promotion, CarbonInterface $at): bool
    {
        if (! $promotion->is_active) {
            return false;
        }

        if (($promotion->starts_at !== null && $at->lt($promotion->starts_at)) || ($promotion->ends_at !== null && $at->gt($promotion->ends_at))) {
            return false;
        }

        $weekdays = $promotion->weekdays ?? [];

        if ($weekdays !== [] && ! in_array($at->dayOfWeekIso, array_map('intval', $weekdays), true)) {
            return false;
        }

        if ($promotion->time_from === null || $promotion->time_to === null) {
            return true;
        }

        $time = $at->format('H:i');

        // A window that crosses midnight (22:00 to 02:00) is open late in the evening or early in the morning.
        return $promotion->time_from <= $promotion->time_to
            ? $time >= $promotion->time_from && $time <= $promotion->time_to
            : $time >= $promotion->time_from || $time <= $promotion->time_to;
    }

    /**
     * @param  array<int, array<string, mixed>>  $state
     * @return list<int>
     */
    private function eligibleLines(RetailPromotion $promotion, array $state): array
    {
        $eligible = [];

        foreach ($state as $index => $line) {
            if ($promotion->product_id !== null && $line['product_id'] !== (string) $promotion->product_id) {
                continue;
            }

            if ($line['locked'] || (! $promotion->is_stackable && bccomp($line['discount'], '0', 2) > 0)) {
                continue;
            }

            $eligible[] = $index;
        }

        return $eligible;
    }

    /**
     * @param  list<int>  $indexes
     * @param  array<int, array<string, mixed>>  $state
     */
    private function remaining(array $indexes, array $state): string
    {
        return array_reduce($indexes, static fn (string $sum, int $index): string => bcadd($sum, bcsub($state[$index]['net'], $state[$index]['discount'], 2), 2), '0.00');
    }

    private function amountOff(RetailPromotion $promotion, string $subtotal): string
    {
        $amount = $promotion->type === 'percentage'
            ? bcround(bcdiv(bcmul($subtotal, (string) $promotion->value, 6), '100', 6), 2)
            : bcadd((string) $promotion->value, '0', 2);

        return bccomp($amount, $subtotal, 2) > 0 ? $subtotal : $amount;
    }

    /**
     * Spreads an amount over the lines in proportion to what is left of them; the last line takes the rounding.
     *
     * @param  list<int>  $indexes
     * @param  array<int, array<string, mixed>>  $state
     * @return array<int, string>
     */
    private function spread(string $amount, array $indexes, array $state): array
    {
        $base = $this->remaining($indexes, $state);
        $left = $amount;
        $result = [];
        $last = array_key_last($indexes);

        foreach ($indexes as $position => $index) {
            $remaining = bcsub($state[$index]['net'], $state[$index]['discount'], 2);
            $share = $position === $last ? $left : bcround(bcdiv(bcmul($amount, $remaining, 6), $base, 6), 2);

            if (bccomp($share, $remaining, 2) > 0) {
                $share = $remaining;
            }

            $left = bcsub($left, $share, 2);

            if (bccomp($share, '0', 2) > 0) {
                $result[$index] = $share;
            }
        }

        return $result;
    }

    /**
     * @param  list<int>  $indexes
     * @param  array<int, array<string, mixed>>  $state
     * @return array<int, string>
     */
    private function freeUnits(RetailPromotion $promotion, array $indexes, array $state): array
    {
        $buy = (int) $promotion->buy_quantity;
        $get = (int) $promotion->get_quantity;

        if ($promotion->product_id === null || $buy < 1 || $get < 1) {
            return [];
        }

        $units = [];

        foreach ($indexes as $index) {
            for ($unit = 0; $unit < (int) floor((float) $state[$index]['quantity']); $unit++) {
                $units[] = ['index' => $index, 'price' => $state[$index]['unit_price']];
            }
        }

        $free = intdiv(count($units), $buy + $get) * $get;

        usort($units, static fn (array $a, array $b): int => bccomp($a['price'], $b['price'], 4));

        $result = [];

        foreach (array_slice($units, 0, $free) as $unit) {
            $result[$unit['index']] = bcadd($result[$unit['index']] ?? '0.00', bcround($unit['price'], 2), 2);
        }

        foreach ($result as $index => $amount) {
            $remaining = bcsub($state[$index]['net'], $state[$index]['discount'], 2);
            $result[$index] = bccomp($amount, $remaining, 2) > 0 ? $remaining : $amount;
        }

        return $result;
    }
}
