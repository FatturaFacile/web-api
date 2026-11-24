<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Domain\Services;

/**
 * Compares the lines of an extracted invoice with the lines of one purchase order and what was received against it
 * (three-way match, Mod 048): price variance against the order, quantity variance against the order and the goods
 * receipts. A line is within tolerance when its price differs by at most the tolerance percent (or amount) and the
 * invoice does not bill more than was received.
 */
final readonly class ThreeWayMatchEvaluator
{
    private const float MinimumLineSimilarity = 0.5;

    public function __construct(private LineDescriptionMatcher $matcher = new LineDescriptionMatcher) {}

    /**
     * @param  list<array{description: string, quantity: float|null, unit_price: float|null}>  $invoiceLines
     * @param  list<array{id: string, description: string, quantity: float, unit_price: float, quantity_received: float}>  $orderLines
     * @return array{status: string, lines: list<array<string, mixed>>, unmatched_invoice_lines: list<int>}
     */
    public function evaluate(array $invoiceLines, array $orderLines, float $tolerancePercent, float $toleranceAmount): array
    {
        $patterns = [];

        foreach ($orderLines as $index => $line) {
            $patterns[$index] = $this->matcher->normalize($line['description']);
        }

        $used = [];
        $matches = [];
        $unmatched = [];

        foreach ($invoiceLines as $invoiceIndex => $invoiceLine) {
            $pattern = $this->matcher->normalize($invoiceLine['description']);
            $best = null;
            $bestScore = self::MinimumLineSimilarity;

            foreach ($patterns as $orderIndex => $orderPattern) {
                if (isset($used[$orderIndex])) {
                    continue;
                }

                $score = $this->matcher->similarity($pattern, $orderPattern);

                if ($score >= $bestScore) {
                    $best = $orderIndex;
                    $bestScore = $score;
                }
            }

            if ($best === null) {
                $unmatched[] = $invoiceIndex;

                continue;
            }

            $used[$best] = true;
            $matches[] = $this->compare($invoiceIndex, $invoiceLine, $orderLines[$best], $tolerancePercent, $toleranceAmount);
        }

        $withinTolerance = array_reduce($matches, static fn (bool $carry, array $match): bool => $carry && $match['within_tolerance'], true);

        $status = match (true) {
            $matches === [] => 'unmatched',
            $unmatched === [] && $withinTolerance => 'matched',
            default => 'variance',
        };

        return ['status' => $status, 'lines' => $matches, 'unmatched_invoice_lines' => $unmatched];
    }

    /**
     * @param  array{description: string, quantity: float|null, unit_price: float|null}  $invoiceLine
     * @param  array{id: string, description: string, quantity: float, unit_price: float, quantity_received: float}  $orderLine
     * @return array<string, mixed>
     */
    private function compare(int $invoiceIndex, array $invoiceLine, array $orderLine, float $tolerancePercent, float $toleranceAmount): array
    {
        $quantity = $invoiceLine['quantity'] ?? 1.0;
        $priceVariance = round(($invoiceLine['unit_price'] ?? $orderLine['unit_price']) - $orderLine['unit_price'], 4);
        $priceVariancePercent = $orderLine['unit_price'] > 0 ? round($priceVariance / $orderLine['unit_price'] * 100, 2) : 0.0;
        $quantityVariance = round($quantity - $orderLine['quantity'], 4);
        $receivedVariance = round($quantity - $orderLine['quantity_received'], 4);

        $priceOk = abs($priceVariancePercent) <= $tolerancePercent || abs($priceVariance * $quantity) <= $toleranceAmount;

        return [
            'invoice_line_index' => $invoiceIndex,
            'purchase_order_line_id' => $orderLine['id'],
            'price_variance' => $priceVariance,
            'price_variance_percent' => $priceVariancePercent,
            'quantity_variance' => $quantityVariance,
            'received_variance' => $receivedVariance,
            'within_tolerance' => $priceOk && $receivedVariance <= 0.0,
        ];
    }
}
