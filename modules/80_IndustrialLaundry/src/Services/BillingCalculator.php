<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Services;

use Illuminate\Support\Carbon;
use Modules\IndustrialLaundry\Domain\Enums\ArticleType;
use Modules\IndustrialLaundry\Domain\Enums\BillingLineKind;
use Modules\IndustrialLaundry\Models\ParContract;
use Modules\IndustrialLaundry\Models\ParContractLine;
use Modules\IndustrialLaundry\Support\Money;

/**
 * The arithmetic of the monthly bill, with no database: wash per piece or per kg, the weekly (or seasonal) guaranteed minimum,
 * the rental of the garments in circulation and the garments still at the customer after the threshold, valued at the
 * replacement price.
 *
 * Facts: `delivered` (one entry per garment delivered clean in the period: article type and moment), `net_kg` (net weight of the
 * weighings the contract bills), `active_garments` (article type => garments assigned to the customer and not condemned) and
 * `unreturned` (garments past the threshold: id, epc, article type).
 *
 * @phpstan-type Fact array{delivered: list<array{article_type: string, at: Carbon}>, net_kg: string, active_garments: array<string, int>, unreturned: list<array{id: string, epc: string, article_type: string}>}
 * @phpstan-type Line array{kind: BillingLineKind, article_type: ArticleType|null, quantity: string, uom: string, unit_price: string, amount: string, detail: array<string, mixed>}
 */
final class BillingCalculator
{
    /**
     * @param  Fact  $facts
     * @return array{lines: list<Line>, washed_pieces: int, net_weight_kg: string, wash_amount: string, minimum_top_up_amount: string, rental_amount: string, unreturned_amount: string, subtotal: string, tax_rate: string, tax_amount: string, total: string}
     */
    public function calculate(ParContract $contract, array $facts, Carbon $start, Carbon $end): array
    {
        $lines = [];
        $model = $contract->billing_model;
        $pieces = $this->piecesByType($facts['delivered']);

        if ($model->billsWashPerPiece()) {
            $lines = [...$lines, ...$this->washPerPiece($contract, $pieces, $facts['delivered'], $start, $end)];
        }

        if ($model->billsWashPerKg()) {
            $lines = [...$lines, ...$this->washPerKg($contract, $pieces, $facts['net_kg'])];
        }

        if ($model->billsRental()) {
            $lines = [...$lines, ...$this->rental($contract, $facts['active_garments'], $start, $end)];
        }

        $lines = [...$lines, ...$this->unreturned($contract, $facts['unreturned'], $end)];

        return $this->totals($contract, $lines, array_sum($pieces), $facts['net_kg']);
    }

    /**
     * The pieces the weekly or seasonal guaranteed minimum tops up for one line, week by week.
     *
     * @param  list<Carbon>  $deliveries  The moments the garments of the article type were delivered in the period.
     */
    public function topUpPieces(ParContractLine $line, array $deliveries, Carbon $start, Carbon $end): int
    {
        $topUp = 0;
        $cursor = $start->copy()->startOfDay();
        $last = $end->copy()->endOfDay();

        while ($cursor->lessThanOrEqualTo($last)) {
            $weekEnd = $cursor->copy()->endOfWeek()->min($last);
            $days = (int) $cursor->copy()->startOfDay()->diffInDays($weekEnd->copy()->startOfDay()) + 1;
            $actual = count(array_filter($deliveries, static fn (Carbon $at): bool => $at->greaterThanOrEqualTo($cursor) && $at->lessThanOrEqualTo($weekEnd)));
            $minimum = $this->weeklyMinimum($line, $this->percentageFor($line, $cursor), $days);
            $topUp += max(0, $minimum - $actual);
            $cursor = $weekEnd->copy()->addDay()->startOfDay();
        }

        return $topUp;
    }

    /**
     * Pieces of the guaranteed minimum of a (part of a) week: the par level times the percentage, prorated by the days of the
     * week inside the period, rounded up to whole pieces.
     */
    public function weeklyMinimum(ParContractLine $line, string $percentage, int $days): int
    {
        $weekly = Money::percentOf((string) $line->circulating_stock_qty, $percentage);
        $prorated = Money::round(bcdiv(bcmul($weekly, (string) $days, 8), '7', 8));

        return (int) ceil((float) $prorated);
    }

    public function percentageFor(ParContractLine $line, Carbon $day): string
    {
        $seasonal = $line->seasonal_min_billing_percentage;

        if ($seasonal !== null && $line->season_start_month !== null && $line->season_end_month !== null && $this->inSeason((int) $day->month, $line->season_start_month, $line->season_end_month)) {
            return (string) $seasonal;
        }

        return (string) $line->weekly_min_billing_percentage;
    }

    private function inSeason(int $month, int $from, int $to): bool
    {
        return $from <= $to ? ($month >= $from && $month <= $to) : ($month >= $from || $month <= $to);
    }

    /**
     * @param  list<array{article_type: string, at: Carbon}>  $delivered
     * @return array<string, int>
     */
    private function piecesByType(array $delivered): array
    {
        $pieces = [];

        foreach ($delivered as $entry) {
            $pieces[$entry['article_type']] = ($pieces[$entry['article_type']] ?? 0) + 1;
        }

        ksort($pieces);

        return $pieces;
    }

    /**
     * @param  array<string, int>  $pieces
     * @param  list<array{article_type: string, at: Carbon}>  $delivered
     * @return list<Line>
     */
    private function washPerPiece(ParContract $contract, array $pieces, array $delivered, Carbon $start, Carbon $end): array
    {
        $lines = [];

        foreach ($contract->lines->sortBy(fn (ParContractLine $line): string => $line->article_type->value) as $line) {
            $type = $line->article_type;
            $price = Money::from($line->unit_wash_price);
            $quantity = $pieces[$type->value] ?? 0;

            if ($quantity > 0 && Money::isPositive($price)) {
                $lines[] = $this->line(BillingLineKind::WashPiece, $type, (string) $quantity, 'PZ', $price, Money::mul((string) $quantity, $price), []);
            }

            $moments = array_map(static fn (array $entry): Carbon => $entry['at'], array_values(array_filter($delivered, static fn (array $entry): bool => $entry['article_type'] === $type->value)));
            $hasMinimum = Money::isPositive(Money::from($line->weekly_min_billing_percentage)) || $line->seasonal_min_billing_percentage !== null;
            $topUp = $hasMinimum ? $this->topUpPieces($line, $moments, $start, $end) : 0;

            if ($topUp > 0 && Money::isPositive($price)) {
                $lines[] = $this->line(BillingLineKind::MinimumTopUp, $type, (string) $topUp, 'PZ', $price, Money::mul((string) $topUp, $price), ['par_level' => $line->circulating_stock_qty, 'weekly_min_billing_percentage' => (string) $line->weekly_min_billing_percentage]);
            }
        }

        return $lines;
    }

    /**
     * One line for the net weight, at the rate of the article types weighted by the pieces delivered (the weighing does not split
     * the weight by article), or at the plain average of the contract rates when nothing was delivered by piece.
     *
     * @param  array<string, int>  $pieces
     * @return list<Line>
     */
    private function washPerKg(ParContract $contract, array $pieces, string $netKg): array
    {
        if (! Money::isPositive($netKg)) {
            return [];
        }

        $weighted = '0';
        $count = 0;
        $positive = [];

        foreach ($contract->lines as $line) {
            $rate = Money::from($line->price_per_kg);

            if (! Money::isPositive($rate)) {
                continue;
            }

            $positive[] = $rate;
            $quantity = $pieces[$line->article_type->value] ?? 0;
            $weighted = Money::add($weighted, Money::mul((string) $quantity, $rate));
            $count += $quantity;
        }

        if ($positive === []) {
            return [];
        }

        $rate = $count > 0
            ? Money::round(bcdiv($weighted, (string) $count, 8))
            : Money::round(bcdiv(Money::sum($positive), (string) count($positive), 8));

        return [$this->line(BillingLineKind::WashKg, null, Money::weight($netKg), 'KG', $rate, Money::mul(Money::weight($netKg), $rate), ['basis' => $contract->kg_basis->value])];
    }

    /**
     * @param  array<string, int>  $active
     * @return list<Line>
     */
    private function rental(ParContract $contract, array $active, Carbon $start, Carbon $end): array
    {
        $daysInMonth = (int) $start->copy()->daysInMonth;
        $days = (int) $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1;
        $fraction = $days >= $daysInMonth ? '1' : Money::round(bcdiv((string) $days, (string) $daysInMonth, 8));
        $lines = [];

        foreach ($contract->lines->sortBy(fn (ParContractLine $line): string => $line->article_type->value) as $line) {
            $quantity = $active[$line->article_type->value] ?? 0;
            $price = Money::from($line->rental_price);

            if ($quantity > 0 && Money::isPositive($price)) {
                $unit = Money::mul($price, $fraction);
                $lines[] = $this->line(BillingLineKind::Rental, $line->article_type, (string) $quantity, 'PZ', $unit, Money::mul((string) $quantity, $unit), ['monthly_price' => $price, 'fraction' => $fraction]);
            }
        }

        return $lines;
    }

    /**
     * @param  list<array{id: string, epc: string, article_type: string}>  $unreturned
     * @return list<Line>
     */
    private function unreturned(ParContract $contract, array $unreturned, Carbon $end): array
    {
        $byType = [];

        foreach ($unreturned as $garment) {
            $byType[$garment['article_type']][] = $garment;
        }

        ksort($byType);
        $lines = [];

        foreach ($byType as $type => $garments) {
            $line = $contract->lines->first(fn (ParContractLine $candidate): bool => $candidate->article_type->value === $type);
            $price = $line !== null && Money::isPositive(Money::from($line->replacement_price)) ? Money::from($line->replacement_price) : Money::from($contract->unreturned_garment_replacement_price);

            if (! Money::isPositive($price)) {
                continue;
            }

            $lines[] = $this->line(BillingLineKind::Unreturned, ArticleType::from($type), (string) count($garments), 'PZ', $price, Money::mul((string) count($garments), $price), [
                'threshold_days' => $contract->unreturned_threshold_days,
                'as_of' => $end->toDateString(),
                'garment_ids' => array_column($garments, 'id'),
                'epcs' => array_column($garments, 'epc'),
            ]);
        }

        return $lines;
    }

    /**
     * @param  list<Line>  $lines
     * @return array{lines: list<Line>, washed_pieces: int, net_weight_kg: string, wash_amount: string, minimum_top_up_amount: string, rental_amount: string, unreturned_amount: string, subtotal: string, tax_rate: string, tax_amount: string, total: string}
     */
    private function totals(ParContract $contract, array $lines, int $pieces, string $netKg): array
    {
        $sum = fn (array $kinds): string => Money::round(Money::sum(array_map(static fn (array $line): string => $line['amount'], array_filter($lines, static fn (array $line): bool => in_array($line['kind'], $kinds, true)))), 2);
        $wash = $sum([BillingLineKind::WashPiece, BillingLineKind::WashKg]);
        $topUp = $sum([BillingLineKind::MinimumTopUp]);
        $rental = $sum([BillingLineKind::Rental]);
        $unreturned = $sum([BillingLineKind::Unreturned]);
        $subtotal = Money::round(Money::sum([$wash, $topUp, $rental, $unreturned]), 2);
        $rate = Money::from($contract->tax_rate);
        $tax = Money::round(Money::percentOf($subtotal, $rate), 2);

        return [
            'lines' => $lines,
            'washed_pieces' => $pieces,
            'net_weight_kg' => Money::weight($netKg),
            'wash_amount' => $wash,
            'minimum_top_up_amount' => $topUp,
            'rental_amount' => $rental,
            'unreturned_amount' => $unreturned,
            'subtotal' => $subtotal,
            'tax_rate' => $rate,
            'tax_amount' => $tax,
            'total' => Money::round(Money::add($subtotal, $tax), 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return Line
     */
    private function line(BillingLineKind $kind, ?ArticleType $type, string $quantity, string $uom, string $unitPrice, string $amount, array $detail): array
    {
        return ['kind' => $kind, 'article_type' => $type, 'quantity' => $quantity, 'uom' => $uom, 'unit_price' => $unitPrice, 'amount' => Money::round($amount, 2), 'detail' => $detail];
    }
}
