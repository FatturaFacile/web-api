<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Services;

use Modules\Quoting\Infrastructure\Models\CpqQuote;
use Modules\Quoting\Infrastructure\Services\QuoteSnapshotBuilder;

/**
 * The lines of an accepted quote as the sales documents want them: the mandatory lines plus the optional and alternative lines
 * the customer picked, each at its agreed net price (the whole discount cascade is already in the unit price, so the document
 * totals match the signed proposal to the cent).
 */
final class QuoteBillableLines
{
    public function __construct(private readonly QuoteSnapshotBuilder $snapshots) {}

    /**
     * @return list<array{product_id: string|null, description: string, quantity: string, unit_price: string, discount_percentage: string, tax_rate: string, uom: string, net: string}>
     */
    public function of(CpqQuote $quote): array
    {
        $view = $this->snapshots->publicView($quote, (array) $quote->customer_selections);
        $products = [];
        foreach ((array) ($quote->frozen_snapshot['lines'] ?? []) as $line) {
            $products[(string) $line['id']] = $line['product_id'] ?? null;
        }

        $items = [];
        foreach ($view['lines'] as $line) {
            if (! $line['selected'] || ! in_array($line['line_type'], ['product', 'service_fixed', 'service_time_and_material'], true)) {
                continue;
            }

            $quantity = (string) $line['quantity'];
            $items[] = [
                'product_id' => $products[(string) $line['id']] ?? null,
                'description' => (string) $line['description'],
                'quantity' => $quantity,
                'unit_price' => bccomp($quantity, '0', 4) === 0 ? '0' : bcdiv((string) $line['net'], $quantity, 4),
                'discount_percentage' => '0',
                'tax_rate' => (string) $line['tax_rate'],
                'uom' => (string) ($line['uom'] ?? ''),
                'net' => (string) $line['net'],
            ];
        }

        return $items;
    }

    /**
     * One line per VAT rate for a down payment of the given percentage of the net total.
     *
     * @return list<array{description: string, quantity: string, unit_price: string, discount_percentage: string, tax_rate: string}>
     */
    public function downPayment(CpqQuote $quote, string $percent): array
    {
        $byRate = [];
        foreach ($this->of($quote) as $item) {
            $byRate[$item['tax_rate']] = bcadd($byRate[$item['tax_rate']] ?? '0', $item['net'], 2);
        }

        $lines = [];
        foreach ($byRate as $rate => $net) {
            $amount = bcadd(bcdiv(bcmul($net, $percent, 6), '100', 6), '0.005', 2);
            $lines[] = [
                'description' => "Down payment {$percent}% - quote {$quote->quote_number}",
                'quantity' => '1',
                'unit_price' => $amount,
                'discount_percentage' => '0',
                'tax_rate' => (string) $rate,
            ];
        }

        return $lines;
    }
}
