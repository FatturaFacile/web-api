<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\QuoteStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\SalesReturnStatus;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesReturn;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Sales performance indicators of the active company, read from the sales documents (no stored aggregates):
 *
 * - order backlog: the open orders (confirmed up to in fulfilment, on hold included) and the net value of what they
 *   still have to deliver, at the net price of each line;
 * - fill rate of the orders dated in the period (drafts, cancelled and rejected orders left out): the share of order
 *   lines delivered in full and the share of the ordered quantity delivered;
 * - quote conversion of the quotes dated in the period (superseded revisions left out): accepted over decided
 *   (accepted, rejected, expired), and the share converted into an order, with their values;
 * - returns opened in the period.
 *
 * Amounts are decimal strings with 2 decimals, rates percentages with 2 decimals (null when there is nothing to divide).
 */
final class SalesKpiService
{
    /**
     * Order statuses that make up the backlog.
     *
     * @var list<string>
     */
    private const array OpenOrderStatuses = ['confirmed', 'approved', 'on_hold', 'in_production', 'in_fulfillment'];

    /**
     * @return array{
     *     period: array{from: string, to: string},
     *     order_backlog: array{open_orders: int, open_lines: int, open_net_value: string},
     *     fill_rate: array{orders: int, lines: int, lines_fully_delivered: int, line_fill_rate: ?string, quantity_ordered: string, quantity_delivered: string, quantity_fill_rate: ?string},
     *     quote_conversion: array{quotes: int, accepted: int, rejected: int, expired: int, open: int, converted_to_order: int, quoted_net_value: string, accepted_net_value: string, conversion_rate: ?string, order_conversion_rate: ?string},
     *     returns: array{opened: int, credited: int}
     * }
     */
    public function compute(string $from, string $to): array
    {
        return [
            'period' => ['from' => $from, 'to' => $to],
            'order_backlog' => $this->backlog(),
            'fill_rate' => $this->fillRate($from, $to),
            'quote_conversion' => $this->quoteConversion($from, $to),
            'returns' => [
                'opened' => SalesReturn::query()->whereBetween('date', [$from, $to])->count(),
                'credited' => SalesReturn::query()->whereBetween('date', [$from, $to])->where('status', SalesReturnStatus::Credited->value)->count(),
            ],
        ];
    }

    /**
     * @return array{open_orders: int, open_lines: int, open_net_value: string}
     */
    private function backlog(): array
    {
        $orderIds = SalesOrder::query()->whereIn('status', self::OpenOrderStatuses)->pluck('id');
        $openLines = 0;
        $value = '0';

        SalesOrderItem::query()->whereIn('sales_order_id', $orderIds)->orderBy('id')->chunk(500, function ($items) use (&$openLines, &$value): void {
            foreach ($items as $item) {
                $quantity = DocumentTotalsCalculator::decimal($item->quantity);
                $open = Decimal::sub($quantity, DocumentTotalsCalculator::decimal($item->quantity_delivered));

                if (! Decimal::isPositive($open) || ! Decimal::isPositive($quantity)) {
                    continue;
                }

                $openLines++;
                $value = Decimal::add($value, Decimal::div(Decimal::mul(DocumentTotalsCalculator::decimal($item->line_total), $open), $quantity));
            }
        });

        return ['open_orders' => $orderIds->count(), 'open_lines' => $openLines, 'open_net_value' => Decimal::round2($value)];
    }

    /**
     * @return array{orders: int, lines: int, lines_fully_delivered: int, line_fill_rate: ?string, quantity_ordered: string, quantity_delivered: string, quantity_fill_rate: ?string}
     */
    private function fillRate(string $from, string $to): array
    {
        $orderIds = SalesOrder::query()
            ->whereBetween('date', [$from, $to])
            ->whereNotIn('status', [SalesOrderStatus::Draft->value, SalesOrderStatus::Cancelled->value, SalesOrderStatus::Rejected->value])
            ->pluck('id');

        $lines = 0;
        $full = 0;
        $ordered = '0';
        $delivered = '0';

        foreach (SalesOrderItem::query()->whereIn('sales_order_id', $orderIds)->get(['quantity', 'quantity_delivered']) as $item) {
            $quantity = DocumentTotalsCalculator::decimal($item->quantity);
            $shipped = DocumentTotalsCalculator::decimal($item->quantity_delivered);
            $lines++;
            $ordered = Decimal::add($ordered, $quantity);
            $delivered = Decimal::add($delivered, Decimal::compare($shipped, $quantity) > 0 ? $quantity : $shipped);

            if (Decimal::compare($shipped, $quantity) >= 0) {
                $full++;
            }
        }

        return [
            'orders' => $orderIds->count(),
            'lines' => $lines,
            'lines_fully_delivered' => $full,
            'line_fill_rate' => self::rate((string) $full, (string) $lines),
            'quantity_ordered' => bcadd($ordered, '0', 4),
            'quantity_delivered' => bcadd($delivered, '0', 4),
            'quantity_fill_rate' => self::rate($delivered, $ordered),
        ];
    }

    /**
     * @return array{quotes: int, accepted: int, rejected: int, expired: int, open: int, converted_to_order: int, quoted_net_value: string, accepted_net_value: string, conversion_rate: ?string, order_conversion_rate: ?string}
     */
    private function quoteConversion(string $from, string $to): array
    {
        $quotes = Quote::query()
            ->whereBetween('date', [$from, $to])
            ->where('status', '!=', QuoteStatus::Superseded->value)
            ->get(['id', 'status', 'total_net']);

        $convertedIds = SalesOrder::query()->whereIn('quote_id', $quotes->pluck('id'))->distinct()->pluck('quote_id')->map(static fn ($id): string => (string) $id)->all();
        $counts = ['accepted' => 0, 'rejected' => 0, 'expired' => 0, 'open' => 0];
        $quoted = '0';
        $won = '0';

        foreach ($quotes as $quote) {
            $net = DocumentTotalsCalculator::decimal($quote->total_net);
            $quoted = Decimal::add($quoted, $net);
            $bucket = match ($quote->status) {
                QuoteStatus::Accepted->value, 'converted' => 'accepted',
                QuoteStatus::Rejected->value => 'rejected',
                QuoteStatus::Expired->value => 'expired',
                default => 'open',
            };
            $counts[$bucket]++;

            if ($bucket === 'accepted') {
                $won = Decimal::add($won, $net);
            }
        }

        $decided = $counts['accepted'] + $counts['rejected'] + $counts['expired'];

        return [
            'quotes' => $quotes->count(),
            'accepted' => $counts['accepted'],
            'rejected' => $counts['rejected'],
            'expired' => $counts['expired'],
            'open' => $counts['open'],
            'converted_to_order' => count($convertedIds),
            'quoted_net_value' => Decimal::round2($quoted),
            'accepted_net_value' => Decimal::round2($won),
            'conversion_rate' => self::rate((string) $counts['accepted'], (string) $decided),
            'order_conversion_rate' => self::rate((string) count($convertedIds), (string) $quotes->count()),
        ];
    }

    private static function rate(string $part, string $whole): ?string
    {
        if (! Decimal::isPositive($whole)) {
            return null;
        }

        return Decimal::round2(Decimal::div(Decimal::mul($part, '100'), $whole));
    }
}
